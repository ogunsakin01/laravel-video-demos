<?php

namespace Ogunsakin01\VideoDemo\Ai\Drivers;

/**
 * How the package talks to a model provider. The planner keeps the conversation in the driver's
 * own message format and only ever appends to it; the driver turns each reply into a Turn.
 */
interface Driver
{
    /**
     * Append a user message to the conversation.
     */
    public function addUserText(array &$messages, string $text): void;

    /**
     * Send the conversation and append the model's reply to it.
     *
     * @param  list<array{name: string, description: string, schema: array}>  $tools
     * @param  bool  $longCache  Keep the conversation cached for an hour, where the provider allows.
     */
    public function send(array &$messages, string $system, array $tools, string $model, string $effort, bool $longCache = false): Turn;

    /**
     * Append the results of the last turn's tool calls to the conversation.
     *
     * @param  list<ToolOutput>  $outputs
     */
    public function addToolOutputs(array &$messages, array $outputs): void;

    /**
     * Get one answer matching a JSON schema, for short tasks such as "Complete with AI".
     *
     * @return array{data: array, turn: Turn}
     */
    public function structured(string $system, string $prompt, array $schema, string $model): array;
}
