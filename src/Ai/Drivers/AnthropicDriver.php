<?php

namespace Ogunsakin01\VideoDemo\Ai\Drivers;

use Ogunsakin01\VideoDemo\Ai\Claude;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Ai\Usage;

/**
 * The default driver: Claude through the Anthropic SDK, with prompt caching,
 * effort control, strict tool schemas and fallbacks when a request is declined.
 */
class AnthropicDriver implements Driver
{
    protected const MAX_TOKENS = 16_000;

    /**
     * Create a new Anthropic driver instance.
     */
    public function __construct(protected Claude $claude) {}

    /**
     * Append a user message to the conversation.
     */
    public function addUserText(array &$messages, string $text): void
    {
        $messages[] = ['role' => 'user', 'content' => $text];
    }

    /**
     * Send the conversation to Claude and append its reply.
     */
    public function send(array &$messages, string $system, array $tools, string $model, string $effort, bool $longCache = false): Turn
    {
        $response = $this->claude->create([
            'model' => $model,
            'maxTokens' => self::MAX_TOKENS,
            'system' => $system,
            'tools' => array_map(fn ($t) => [
                'name' => $t['name'],
                'description' => $t['description'],
                'inputSchema' => $t['schema'],
                'strict' => true,
            ], $tools),
            'thinking' => ['type' => 'adaptive'],
            'outputConfig' => ['effort' => $effort],
            // Cache everything up to the latest message, so each request pays the
            // cheaper cached price for the conversation so far.
            'cacheControl' => $longCache ? ['type' => 'ephemeral', 'ttl' => '1h'] : ['type' => 'ephemeral'],
            'messages' => $messages,
        ]);

        // The whole reply, thinking blocks included, must go back unchanged.
        $messages[] = ['role' => 'assistant', 'content' => $response->content];

        return $this->turn($response, $model);
    }

    /**
     * Append the results of the last turn's tool calls to the conversation.
     */
    public function addToolOutputs(array &$messages, array $outputs): void
    {
        $messages[] = ['role' => 'user', 'content' => array_map(fn (ToolOutput $o) => array_filter([
            'type' => 'tool_result',
            'toolUseID' => $o->id,
            'content' => $o->content,
            'isError' => $o->isError ?: null,
        ], fn ($v) => $v !== null), $outputs)];
    }

    /**
     * Get one answer matching a JSON schema.
     */
    public function structured(string $system, string $prompt, array $schema, string $model): array
    {
        $response = $this->claude->create([
            'model' => $model,
            'maxTokens' => 4_000,
            'system' => $system,
            'outputConfig' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        $turn = $this->turn($response, $model);

        return ['data' => json_decode($turn->text, true) ?: [], 'turn' => $turn];
    }

    /**
     * Convert a Messages API response into a turn.
     */
    protected function turn(object $response, string $requestedModel): Turn
    {
        $text = '';
        $calls = [];
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            } elseif ($block->type === 'tool_use') {
                $calls[] = ['id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
            }
        }

        // Writes to the 1-hour cache cost more than writes to the 5-minute one.
        $write1h = (int) ($response->usage->cacheCreation->ephemeral1hInputTokens ?? 0);

        // After a fallback the answering model may differ, so price by it when known.
        $model = Pricing::model($response->model) ? $response->model : $requestedModel;

        return new Turn(
            stop: match ($response->stopReason) {
                'tool_use' => Turn::TOOL_USE,
                'max_tokens' => Turn::MAX_TOKENS,
                'refusal' => Turn::REFUSAL,
                default => Turn::END,
            },
            toolCalls: $calls,
            usage: Usage::fromCounts(
                $model,
                (int) $response->usage->inputTokens,
                (int) ($response->usage->cacheCreationInputTokens ?? 0) - $write1h,
                (int) ($response->usage->cacheReadInputTokens ?? 0),
                (int) $response->usage->outputTokens,
                $write1h,
            ),
            model: $model,
            text: $text,
            refusal: $response->stopReason === 'refusal' ? ($response->stopDetails->explanation ?? '') : null,
        );
    }
}
