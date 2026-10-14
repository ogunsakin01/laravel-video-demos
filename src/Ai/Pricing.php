<?php

namespace Ogunsakin01\VideoDemo\Ai;

use InvalidArgumentException;

/**
 * Prices from config('video-demo.ai.models'), in US dollars per million
 * tokens. A model without prices still works; its cost shows as unknown.
 */
class Pricing
{
    /** @return array{label?: string, provider?: string, input?: ?float, output?: ?float, cache_read?: ?float} */
    public static function for(string $model): array
    {
        $entry = config("video-demo.ai.models.$model");

        if (! is_array($entry)) {
            throw new InvalidArgumentException("Unknown model [$model]. Add it to video-demo.ai.models.");
        }

        return $entry;
    }

    /** Whether the model has prices, so costs and estimates can be shown */
    public static function known(string $model): bool
    {
        $p = config("video-demo.ai.models.$model");

        return is_array($p) && isset($p['input'], $p['output']);
    }

    public static function cost(string $model, int $input, int $cacheWrite, int $cacheRead, int $output): float
    {
        if (! static::known($model)) {
            return 0.0;
        }

        $p = static::for($model);
        $writeMultiplier = (float) config('video-demo.ai.cache_write_multiplier', 1.25);

        return ($input * $p['input']
            + $cacheWrite * $p['input'] * $writeMultiplier
            + $cacheRead * ($p['cache_read'] ?? $p['input'])
            + $output * $p['output']) / 1_000_000;
    }

    /** @return array<string, string> model id => label */
    public static function options(): array
    {
        return collect(config('video-demo.ai.models'))->map(fn ($p, $id) => $p['label'] ?? $id)->all();
    }
}
