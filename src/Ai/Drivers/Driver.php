<?php

namespace Ogunsakin01\VideoDemo\Ai\Drivers;

/**
 * How the package talks to a model provider. The planner holds a
 * conversation in the driver's own message format and only ever appends to
 * it; the driver turns each reply into a Turn the planner understands.
 *
 * Tools are given provider-neutral: name, description and a JSON schema.
 */
interface Driver
{
    /** Appends the user's first message to $messages */
    public function addUserText(array &$messages, string $text): void;

    /**
     * Sends the conversation and appends the model's reply to $messages.
     *
     * @param  list<array{name: string, description: string, schema: array}>  $tools
     */
    public function send(array &$messages, string $system, array $tools, string $model, string $effort): Turn;

    /**
     * Appends results for the tool calls of the last turn.
     *
     * @param  list<ToolOutput>  $outputs
     */
    public function addToolOutputs(array &$messages, array $outputs): void;

    /**
     * One answer matching a JSON schema, for short tasks such as "Complete
     * with AI".
     *
     * @return array{data: array, turn: Turn}
     */
    public function structured(string $system, string $prompt, array $schema, string $model): array;
}
