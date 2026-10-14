<?php

namespace Workbench\App;

use Ogunsakin01\VideoDemo\Ai\Claude;
use RuntimeException;

/**
 * Stands in for Claude in the demo app when no ANTHROPIC_API_KEY is set, so
 * the tours can be recorded for free. "Complete with AI" gets a canned reply;
 * generating a video needs a real key.
 */
class DemoClaude extends Claude
{
    public function __construct() {}

    public function create(array $params): object
    {
        if (! isset($params['outputConfig']['format'])) {
            throw new RuntimeException('The demo app has no ANTHROPIC_API_KEY, so it cannot generate videos.');
        }

        usleep(900_000);

        return (object) [
            'model' => $params['model'],
            'stopReason' => 'end_turn',
            'content' => [(object) ['type' => 'text', 'text' => json_encode([
                'title' => 'Approve a holiday request',
                'prompt' => 'How a team manager approves a holiday request: open the request from the Time off inbox, check the dates against the team calendar, approve it, and see the confirmation the employee receives.',
                'start_path' => '/time-off',
                'target_seconds' => 75,
            ])]],
            'usage' => (object) ['inputTokens' => 2_140, 'outputTokens' => 260, 'cacheCreationInputTokens' => 0, 'cacheReadInputTokens' => 0],
        ];
    }
}
