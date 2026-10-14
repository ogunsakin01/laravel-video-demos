<?php

namespace Ogunsakin01\VideoDemo\Ai\Drivers;

/**
 * Picks the driver for a model, from its "provider" in
 * config('video-demo.ai.models'): "anthropic" (the default) uses the
 * Anthropic SDK; anything else goes through Prism.
 */
class Drivers
{
    public function for(string $model): Driver
    {
        $provider = static::provider($model);

        return $provider === 'anthropic'
            ? app(AnthropicDriver::class)
            : new PrismDriver($provider);
    }

    public static function provider(string $model): string
    {
        return config("video-demo.ai.models.$model.provider", 'anthropic');
    }

    /** Only Claude models take the effort setting */
    public static function supportsEffort(string $model): bool
    {
        return static::provider($model) === 'anthropic';
    }
}
