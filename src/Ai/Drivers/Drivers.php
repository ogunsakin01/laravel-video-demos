<?php

namespace Ogunsakin01\VideoDemo\Ai\Drivers;

use Ogunsakin01\VideoDemo\Ai\Pricing;

/**
 * Picks the driver for a model from its "provider" in config('video-demo.ai.models').
 * "anthropic" (the default) uses the Anthropic SDK; anything else goes through Prism.
 */
class Drivers
{
    /**
     * Get the driver for the given model.
     */
    public function for(string $model): Driver
    {
        $provider = static::provider($model);

        return $provider === 'anthropic'
            ? app(AnthropicDriver::class)
            : new PrismDriver($provider);
    }

    /**
     * Get the provider of the given model.
     */
    public static function provider(string $model): string
    {
        return Pricing::model($model)['provider'] ?? 'anthropic';
    }

    /**
     * Determine if the model's provider has an API key set. Local Ollama needs none.
     */
    public static function hasKey(string $model): bool
    {
        $provider = static::provider($model);

        return match ($provider) {
            'anthropic' => filled(config('video-demo.ai.api_key')),
            'ollama' => true,
            default => filled(config("prism.providers.$provider.api_key")),
        };
    }

    /**
     * Determine if any configured model can be used.
     */
    public static function anyKey(): bool
    {
        foreach (array_keys(config('video-demo.ai.models', [])) as $model) {
            if (static::hasKey($model)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the model takes the effort setting. Only Claude models do.
     */
    public static function supportsEffort(string $model): bool
    {
        return static::provider($model) === 'anthropic';
    }
}
