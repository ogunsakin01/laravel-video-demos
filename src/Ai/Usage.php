<?php

namespace Ogunsakin01\VideoDemo\Ai;

/** Tokens one response used, and what they cost in US dollars */
final readonly class Usage
{
    public function __construct(
        public int $input = 0,
        public int $cacheWrite = 0,
        public int $cacheRead = 0,
        public int $output = 0,
        public float $cost = 0.0,
    ) {}

    public static function fromCounts(string $model, int $input, int $cacheWrite, int $cacheRead, int $output): self
    {
        return new self($input, $cacheWrite, $cacheRead, $output, Pricing::cost($model, $input, $cacheWrite, $cacheRead, $output));
    }

    public function plus(self $other): self
    {
        return new self(
            $this->input + $other->input,
            $this->cacheWrite + $other->cacheWrite,
            $this->cacheRead + $other->cacheRead,
            $this->output + $other->output,
            $this->cost + $other->cost,
        );
    }
}
