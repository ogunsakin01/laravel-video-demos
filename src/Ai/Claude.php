<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Anthropic\Client;

/** The one place the package calls the Messages API, so tests can stand in for it */
class Claude
{
    /** Models that can fall back to another model when a request is declined */
    protected const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    public function __construct(protected Client $client) {}

    /** @return object A Message: content, stopReason, stopDetails, model, usage */
    public function create(array $params): object
    {
        if (config('video-demo.ai.fallbacks', true) && in_array($params['model'], self::FALLBACK_MODELS, true)) {
            return $this->client->beta->messages->create(
                ...$params,
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        }

        return $this->client->messages->create(...$params);
    }
}
