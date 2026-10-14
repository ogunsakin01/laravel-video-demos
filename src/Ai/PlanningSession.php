<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Ogunsakin01\VideoDemo\Ai\Drivers\ToolOutput;
use Ogunsakin01\VideoDemo\Recording\Plan;

/**
 * One planning conversation with the model. Kept for the life of a
 * generation job so a failed recording can be repaired in the same
 * conversation, with the code already read still in context (and, for
 * Claude, in the prompt cache).
 */
class PlanningSession
{
    /**
     * Messages so far, in the driver's own format; only ever appended to, so
     * the prompt cache keeps working
     */
    public array $messages = [];

    /**
     * The submit_plan call still waiting for its result (the recording outcome)
     *
     * @var array{id: string, name: string, input: array}|null
     */
    public ?array $pendingSubmit = null;

    /**
     * Results for other tool calls made alongside submit_plan, sent back with it
     *
     * @var list<ToolOutput>
     */
    public array $pendingResults = [];

    public ?Plan $plan = null;

    public int $turns = 0;

    public int $repairs = 0;
}
