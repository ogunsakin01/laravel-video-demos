<?php

namespace Ogunsakin01\VideoDemo\Ai\Drivers;

/** The result of one tool call, sent back to the model */
final readonly class ToolOutput
{
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
        public string $content,
        public bool $isError = false,
    ) {}
}
