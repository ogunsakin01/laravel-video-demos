<?php

namespace Ogunsakin01\VideoDemo\Ai\Drivers;

use Ogunsakin01\VideoDemo\Ai\Usage;

/** One reply from the model, in a form every driver can produce */
final readonly class Turn
{
    public const TOOL_USE = 'tool_use';

    public const END = 'end';

    public const MAX_TOKENS = 'max_tokens';

    public const REFUSAL = 'refusal';

    /**
     * @param  list<array{id: string, name: string, input: array}>  $toolCalls
     */
    public function __construct(
        public string $stop,
        public array $toolCalls,
        public Usage $usage,
        public string $model,
        public string $text = '',
        public ?string $refusal = null,
    ) {}
}
