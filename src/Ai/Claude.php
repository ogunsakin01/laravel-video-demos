<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Anthropic\Client;

/**
 * The one place the package calls the Anthropic Messages API, so tests can replace it.
 */
class Claude
{
    /**
     * The models that can fall back to another model when a request is declined.
     */
    protected const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    /**
     * Create a new Claude client instance.
     */
    public function __construct(protected Client $client) {}

    /**
     * Send a request to the Messages API.
     *
     * @return object A Message: content, stopReason, stopDetails, model, usage.
     */
    public function create(array $params): object
    {
        ['model' => $model, 'maxTokens' => $maxTokens, 'messages' => $messages] = $params;
        $options = array_diff_key($params, array_flip(['model', 'maxTokens', 'messages']));

        if (config('video-demo.ai.fallbacks', true) && in_array($model, self::FALLBACK_MODELS, true)) {
            return $this->client->beta->messages->create(
                $maxTokens, $messages, $model, ...$options,
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        }

        return $this->client->messages->create($maxTokens, $messages, $model, ...$options);
    }
}
