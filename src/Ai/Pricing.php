<?php

namespace Ogunsakin01\VideoDemo\Ai;

use InvalidArgumentException;

/** Prices from config('video-demo.ai.models'), in US dollars per million tokens */
class Pricing
{
    /** @return array{label: string, input: float, output: float, cache_read: float} */
    public static function for(string $model): array
    {
        $prices = config("video-demo.ai.models.$model");

        if (! is_array($prices)) {
            throw new InvalidArgumentException("No prices for model [$model]. Add it to video-demo.ai.models.");
        }

        return $prices;
    }

    public static function cost(string $model, int $input, int $cacheWrite, int $cacheRead, int $output): float
    {
        $p = static::for($model);
        $writeMultiplier = (float) config('video-demo.ai.cache_write_multiplier', 1.25);

        return ($input * $p['input']
            + $cacheWrite * $p['input'] * $writeMultiplier
            + $cacheRead * $p['cache_read']
            + $output * $p['output']) / 1_000_000;
    }

    /** @return array<string, string> model id => label */
    public static function options(): array
    {
        return collect(config('video-demo.ai.models'))->map(fn ($p, $id) => $p['label'] ?? $id)->all();
    }
}
