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
 * Any provider Prism supports: OpenAI, Gemini, Mistral, DeepSeek, Groq, xAI,
 * OpenRouter, Ollama (local models) and more. Needs prism-php/prism, with
 * the provider's key set up in config/prism.php.
 *
 * The planner runs its own loop, so tools here never run inside Prism: each
 * request is one step, and the planner handles the calls it returns.
 */
class PrismDriver implements Driver
{
    protected const MAX_TOKENS = 16_000;

    public function __construct(protected string $provider)
    {
        if (! class_exists(Prism::class)) {
            throw new PlanningFailed("The [$provider] provider needs Prism: composer require prism-php/prism");
        }
    }

    public function addUserText(array &$messages, string $text): void
    {
        $messages[] = new UserMessage($text);
    }

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

    public function addToolOutputs(array &$messages, array $outputs): void
    {
        $messages[] = new ToolResultMessage(array_map(
            fn (ToolOutput $o) => new ToolResult($o->id, $o->name, $o->input, $o->isError ? 'Error: '.$o->content : $o->content),
            $outputs,
        ));
    }

    public function structured(string $system, string $prompt, array $schema, string $model): array
    {
        $response = Prism::structured()
            ->using($this->provider, $model)
            ->withSystemPrompt($system)
            ->withPrompt($prompt)
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

    /** A tool Prism can describe to the model; the planner runs it, not Prism */
    protected function tool(array $definition): \Prism\Prism\Tool
    {
        $tool = Tool::as($definition['name'])->for($definition['description'])->using(fn () => '');

        $required = $definition['schema']['required'] ?? [];
        foreach ($definition['schema']['properties'] ?? [] as $name => $schema) {
            $tool->withParameter(new RawSchema($name, $schema), in_array($name, $required, true));
        }

        return $tool;
    }

    protected function stop(FinishReason $reason, array $toolCalls): string
    {
        return match (true) {
            $toolCalls !== [] => Turn::TOOL_USE,
            $reason === FinishReason::Length => Turn::MAX_TOKENS,
            $reason === FinishReason::ContentFilter => Turn::REFUSAL,
            default => Turn::END,
        };
    }

    protected function usage(\Prism\Prism\ValueObjects\Usage $usage, string $model): Usage
    {
        $cacheRead = (int) ($usage->cacheReadInputTokens ?? 0);
        $cacheWrite = (int) ($usage->cacheWriteInputTokens ?? 0);

        // Providers count cached tokens inside the prompt total; price them once
        return Usage::fromCounts(
            $model,
            max(0, $usage->promptTokens - $cacheRead - $cacheWrite),
            $cacheWrite,
            $cacheRead,
            $usage->completionTokens + (int) ($usage->thoughtTokens ?? 0),
        );
    }
}
