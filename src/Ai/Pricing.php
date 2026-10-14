<?php

namespace Ogunsakin01\VideoDemo\Ai;

use InvalidArgumentException;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;

/**
 * Model prices from config('video-demo.ai.models'), in US dollars per million tokens.
 * A model without prices still works; its cost shows as unknown.
 */
class Pricing
{
    /**
     * Get a model's settings, or fail when the model is not configured.
     *
     * @return array{label?: string, provider?: string, reliable?: bool, input?: ?float, output?: ?float, cache_read?: ?float, long_context?: array{above: int, multiplier: float|int}}
     */
    public static function for(string $model): array
    {
        $entry = static::model($model);

        if (! is_array($entry)) {
            throw new InvalidArgumentException("Unknown model [$model]. Add it to video-demo.ai.models.");
        }

        return $entry;
    }

    /**
     * Get a model's settings, or null when it is not configured.
     *
     * Looked up by key, not with config()'s dot notation, as model names such as "gpt-6.1-sol" contain dots.
     */
    public static function model(string $model): ?array
    {
        $entry = (config('video-demo.ai.models') ?? [])[$model] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * Determine if the model has prices, so costs and estimates can be shown.
     */
    public static function known(string $model): bool
    {
        $p = static::model($model);

        return is_array($p) && isset($p['input'], $p['output']);
    }

    /**
     * Get what one request cost, in US dollars.
     *
     * $cacheWrite is written to the 5-minute cache, $cacheWrite1h to the 1-hour cache. Models with a
     * long-context rate (Haiku) pay it on the whole request when its prompt, cached tokens included,
     * is over the threshold.
     */
    public static function cost(string $model, int $input, int $cacheWrite, int $cacheRead, int $output, int $cacheWrite1h = 0): float
    {
        if (! static::known($model)) {
            return 0.0;
        }

        $p = static::for($model);
        $write5m = (float) config('video-demo.ai.cache_write_multiplier', 1.25);
        $write1h = (float) config('video-demo.ai.cache_write_1h_multiplier', 2.0);

        $cost = ($input * $p['input']
            + $cacheWrite * $p['input'] * $write5m
            + $cacheWrite1h * $p['input'] * $write1h
            + $cacheRead * ($p['cache_read'] ?? $p['input'])
            + $output * $p['output']) / 1_000_000;

        $prompt = $input + $cacheWrite + $cacheWrite1h + $cacheRead;
        if (isset($p['long_context']) && $prompt > $p['long_context']['above']) {
            $cost *= $p['long_context']['multiplier'];
        }

        return $cost;
    }

    /**
     * Get the model new videos start with.
     *
     * That is video-demo.ai.model when set, else the cheapest model marked reliable, else the cheapest with prices.
     */
    public static function defaultModel(): string
    {
        if ($model = config('video-demo.ai.model')) {
            return $model;
        }

        $models = collect(config('video-demo.ai.models'))->filter(fn ($p, $id) => static::known($id));
        // Prefer models whose provider has a key, when any has one.
        $usable = $models->filter(fn ($p, $id) => Drivers::hasKey($id));
        $models = $usable->isNotEmpty() ? $usable : $models;
        $reliable = $models->filter(fn ($p) => $p['reliable'] ?? false);
        $pick = ($reliable->isNotEmpty() ? $reliable : $models)->sortBy(fn ($p) => $p['input'] + $p['output']);

        return $pick->keys()->first() ?? array_key_first(config('video-demo.ai.models'));
    }

    /**
     * Get the models to offer, preferring those whose provider has a key.
     *
     * @return array<string, string> model id => label
     */
    public static function options(): array
    {
        $models = collect(config('video-demo.ai.models'));
        $usable = $models->filter(fn ($p, $id) => Drivers::hasKey($id));

        return ($usable->isNotEmpty() ? $usable : $models)->map(fn ($p, $id) => $p['label'] ?? $id)->all();
    }
}
