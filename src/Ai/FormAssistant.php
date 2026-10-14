<?php

namespace Ogunsakin01\VideoDemo\Ai;

/**
 * Fills in the new-video form from a rough note, or suggests a video when the
 * note is empty. One short request with the app's routes, so it costs about
 * a cent; the planner reads the code properly later.
 */
class FormAssistant
{
    public function __construct(protected Claude $claude, protected CodeTools $code) {}

    /** @return array{title: string, prompt: string, start_path: ?string, target_seconds: int, cost: float} */
    public function complete(string $draft, string $model): array
    {
        $maxSeconds = (int) config('video-demo.limits.max_seconds', 300);
        $notes = trim((string) config('video-demo.ai.notes'));

        $response = $this->claude->create([
            'model' => $model,
            'maxTokens' => 4_000,
            'system' => 'You help a developer describe a short how-to video of their web app. The video will be planned and recorded later from the app\'s code; your job is only the description. Write it the way the app\'s users would ask about the task, in one to three plain sentences: who does it, where it starts and what it ends with. Do not invent features the routes do not suggest.',
            'outputConfig' => [
                'effort' => 'low',
                'format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'Short title, as a user would search for it'],
                            'prompt' => ['type' => 'string', 'description' => 'The description of the video'],
                            'start_path' => ['type' => ['string', 'null'], 'description' => 'The path the video starts on, such as /dashboard'],
                            'target_seconds' => ['type' => 'integer', 'description' => "Suggested length in seconds, 30 to $maxSeconds"],
                        ],
                        'required' => ['title', 'prompt', 'start_path', 'target_seconds'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'messages' => [[
                'role' => 'user',
                'content' => implode("\n\n", array_filter([
                    trim($draft) !== ''
                        ? "<developer_note>\n".trim($draft)."\n</developer_note>\nTurn this note into the video's title and description."
                        : 'Suggest one useful how-to video for this app, the kind new users most need.',
                    $notes !== '' ? "<notes_from_the_developer>\n$notes\n</notes_from_the_developer>" : null,
                    "<routes>\n".$this->code->routeList()."\n</routes>",
                ])),
            ]],
        ]);

        if ($response->stopReason === 'refusal') {
            throw new PlanningFailed('Claude declined to complete this description.');
        }

        $text = '';
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $data = json_decode($text, true);

        if (! is_array($data) || empty($data['prompt'])) {
            throw new PlanningFailed('Claude did not return a description. Try again.');
        }

        $model = config("video-demo.ai.models.{$response->model}") ? $response->model : $model;

        return [
            'title' => (string) $data['title'],
            'prompt' => (string) $data['prompt'],
            'start_path' => isset($data['start_path']) && str_starts_with((string) $data['start_path'], '/') ? $data['start_path'] : null,
            'target_seconds' => max(10, min($maxSeconds, (int) $data['target_seconds'])),
            'cost' => Usage::fromResponse($response->usage, $model)->cost,
        ];
    }
}
