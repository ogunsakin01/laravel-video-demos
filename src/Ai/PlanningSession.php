<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Ogunsakin01\VideoDemo\Ai\Drivers\ToolOutput;
use Ogunsakin01\VideoDemo\Recording\Plan;

/**
 * One planning conversation with the model, kept for the life of a generation job so a failed
 * recording can be repaired with the code already read still in context (and, for Claude, in the prompt cache).
 */
class PlanningSession
{
    /**
     * The messages so far, in the driver's own format.
     *
     * Only ever appended to, so the prompt cache and thinking blocks stay valid.
     */
    public array $messages = [];

    /**
     * The submit_plan call still waiting for its result (the recording outcome).
     *
     * @var array{id: string, name: string, input: array}|null
     */
    public ?array $pendingSubmit = null;

    /**
     * The results for other tool calls made alongside submit_plan, sent back with it.
     *
     * @var list<ToolOutput>
     */
    public array $pendingResults = [];

    public ?Plan $plan = null;

    public int $turns = 0;

    public int $repairs = 0;

    /**
     * What the last request cost, to judge whether the next one fits the
     * video's limit. Kept across repairs, whose first request is the largest.
     */
    public float $lastCost = 0.0;

    /**
     * The tools for the video's sample database, when it is recorded on sample data.
     */
    public ?SampleDataTools $sampleData = null;

    /**
     * Looking at the app's pages, when the model may.
     */
    public ?PageTools $pages = null;
}
