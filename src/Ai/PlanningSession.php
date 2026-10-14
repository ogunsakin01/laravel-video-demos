<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Ogunsakin01\VideoDemo\Recording\Plan;

/**
 * One planning conversation with Claude. Kept for the life of a generation job
 * so a failed recording can be repaired in the same conversation, with the
 * code Claude already read still in context (and in the prompt cache).
 */
class PlanningSession
{
    /** Messages so far; only ever appended to, so the prompt cache keeps working */
    public array $messages = [];

    /** The submit_plan call still waiting for its result (the recording outcome) */
    public ?string $pendingSubmitId = null;

    /** Results for other tool calls made alongside submit_plan, sent back with it */
    public array $pendingResults = [];

    public ?Plan $plan = null;

    public int $turns = 0;

    public int $repairs = 0;
}
