<?php

namespace Ogunsakin01\VideoDemo\Ai\Drivers;

use Ogunsakin01\VideoDemo\Ai\PlanningFailed;
use Ogunsakin01\VideoDemo\Ai\Usage;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Talks to any provider Prism supports, such as OpenAI, Gemini, Mistral or Ollama. Needs prism-php/prism,
 * with the provider's key set in config/prism.php. Tools never run inside Prism; the planner runs them.
 */
class PrismDriver implements Driver
{
    use RedactsSecrets;

    protected const MAX_TOKENS = 16_000;

    /**
     * Create a new Prism driver instance.
     */
    public function __construct(protected string $provider)
    {
        if (! class_exists(Prism::class)) {
            throw new PlanningFailed("The [$provider] provider needs Prism: composer require prism-php/prism");
        }
    }

    /**
     * Append a user message to the conversation.
     */
    public function addUserText(array &$messages, string $text): void
    {
        $messages[] = new UserMessage($this->redact($text));
    }

    /**
     * Send the conversation to the model and append its reply.
     *
     * Each request is a single step, so the planner handles the tool calls it returns.
     */
    public function send(array &$messages, string $system, array $tools, string $model, string $effort, bool $longCache = false): Turn
    {
        $response = Prism::text()
            ->using($this->provider, $model)
            ->withSystemPrompt($system)
            ->withMessages($messages)
            ->withTools(array_map(fn ($t) => $this->tool($t), $tools))
            ->withMaxSteps(1)
            ->withMaxTokens(self::MAX_TOKENS)
            ->withClientOptions(['timeout' => (int) config('video-demo.ai.timeout', 600)])
            ->asText();

        $messages[] = new AssistantMessage($response->text, $response->toolCalls, $response->additionalContent);

        return new Turn(
            stop: $this->stop($response->finishReason, $response->toolCalls),
            toolCalls: array_map(fn (ToolCall $c) => ['id' => $c->id, 'name' => $c->name, 'input' => $c->arguments()], $response->toolCalls),
            usage: $this->usage($response->usage, $model),
            model: $model,
            text: $response->text,
            refusal: $response->finishReason === FinishReason::ContentFilter ? 'The provider filtered the response.' : null,
        );
    }

    /**
     * Append the results of the last turn's tool calls to the conversation.
     */
    public function addToolOutputs(array &$messages, array $outputs): void
    {
        $messages[] = new ToolResultMessage(array_map(
            fn (ToolOutput $o) => new ToolResult($o->id, $o->name, $o->input, ($o->isError ? 'Error: ' : '').$this->redact($o->content)),
            $outputs,
        ));
    }

    /**
     * Get one answer matching a JSON schema.
     */
    public function structured(string $system, string $prompt, array $schema, string $model): array
    {
        $response = Prism::structured()
            ->using($this->provider, $model)
            ->withSystemPrompt($system)
            ->withPrompt($this->redact($prompt))
            ->withSchema(new RawSchema('answer', $schema))
            ->withMaxTokens(4_000)
            ->withClientOptions(['timeout' => 120])
            ->asStructured();

        $turn = new Turn(
            stop: $this->stop($response->finishReason, []),
            toolCalls: [],
            usage: $this->usage($response->usage, $model),
            model: $model,
            text: $response->text,
            refusal: $response->finishReason === FinishReason::ContentFilter ? 'The provider filtered the response.' : null,
        );

        return ['data' => $response->structured ?? [], 'turn' => $turn];
    }

    /**
     * Build a tool Prism can describe to the model. The planner runs it, not Prism.
     */
    protected function tool(array $definition): \Prism\Prism\Tool
    {
        $tool = Tool::as($definition['name'])->for($definition['description'])->using(fn () => '');

        $required = $definition['schema']['required'] ?? [];
        foreach ($definition['schema']['properties'] ?? [] as $name => $schema) {
            $tool->withParameter(new RawSchema($name, $schema), in_array($name, $required, true));
        }

        return $tool;
    }

    /**
     * Get the turn's stop reason from Prism's finish reason.
     */
    protected function stop(FinishReason $reason, array $toolCalls): string
    {
        return match (true) {
            $toolCalls !== [] => Turn::TOOL_USE,
            $reason === FinishReason::Length => Turn::MAX_TOKENS,
            $reason === FinishReason::ContentFilter => Turn::REFUSAL,
            default => Turn::END,
        };
    }

    /**
     * Convert Prism's token counts into priced usage.
     */
    protected function usage(\Prism\Prism\ValueObjects\Usage $usage, string $model): Usage
    {
        $cacheRead = (int) ($usage->cacheReadInputTokens ?? 0);
        $cacheWrite = (int) ($usage->cacheWriteInputTokens ?? 0);

        // Providers count cached tokens inside the prompt total, so price them only once.
        return Usage::fromCounts(
            $model,
            max(0, $usage->promptTokens - $cacheRead - $cacheWrite),
            $cacheWrite,
            $cacheRead,
            $usage->completionTokens + (int) ($usage->thoughtTokens ?? 0),
        );
    }
}
