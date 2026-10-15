<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Drivers\Turn;
use Ogunsakin01\VideoDemo\Models\Video;

/**
 * Fills in the new-video form from a rough note, or suggests a video when the note is empty,
 * and improves a video's description before it is made again. It sends one short request
 * with the app's routes; the planner reads the code later.
 */
class FormAssistant
{
    /**
     * Create a new form assistant instance.
     */
    public function __construct(protected Drivers $drivers, protected CodeTools $code) {}

    /**
     * Complete the new-video form from the developer's draft note.
     *
     * @return array{title: string, prompt: string, start_path: ?string, target_seconds: int, cost: float, cost_known: bool}
     */
    public function complete(string $draft, string $model): array
    {
        $maxSeconds = (int) config('video-demo.limits.max_seconds', 300);
        $notes = trim((string) config('video-demo.ai.notes'));

        $answer = $this->drivers->for($model)->structured(
            system: 'You help a developer describe a short how-to video of their web app. The video will be planned and recorded later from the app\'s code; your job is only the description. Write it the way the app\'s users would ask about the task, in one to three plain sentences: who does it, where it starts and what it ends with. Do not invent features the routes do not suggest.',
            prompt: implode("\n\n", array_filter([
                trim($draft) !== ''
                    ? "<developer_note>\n".trim($draft)."\n</developer_note>\nTurn this note into the video's title and description."
                    : 'Suggest one useful how-to video for this app, the kind new users most need.',
                $notes !== '' ? "<notes_from_the_developer>\n$notes\n</notes_from_the_developer>" : null,
                "<routes>\n".$this->code->routeList()."\n</routes>",
            ])),
            schema: [
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
            model: $model,
        );

        /** @var Turn $turn */
        $turn = $answer['turn'];
        $data = $answer['data'];

        if ($turn->stop === Turn::REFUSAL) {
            throw new PlanningFailed('The model declined to complete this description.');
        }
        if (empty($data['prompt'])) {
            throw new PlanningFailed('The model did not return a description. Try again.');
        }

        return [
            'title' => (string) ($data['title'] ?? ''),
            'prompt' => (string) $data['prompt'],
            'start_path' => isset($data['start_path']) && str_starts_with((string) $data['start_path'], '/') ? $data['start_path'] : null,
            'target_seconds' => max(10, min($maxSeconds, (int) ($data['target_seconds'] ?? 60))),
            'cost' => $turn->usage->cost,
            'cost_known' => Pricing::known($turn->model),
        ];
    }

    /**
     * Suggest a better description for making a video again, from how the
     * last attempt went: its plan, the steps that failed, the pages that
     * didn't work and how long it came out. $wish is what the developer
     * wants better, if anything. The cost is added to the video's.
     *
     * @return array{prompt: string, start_path: ?string, target_seconds: int, changes: list<string>, cost: float, cost_known: bool}
     */
    public function improve(Video $video, string $wish, string $model): array
    {
        $maxSeconds = (int) config('video-demo.limits.max_seconds', 300);
        $notes = trim((string) config('video-demo.ai.notes'));

        $answer = $this->drivers->for($model)->structured(
            system: 'You help a developer improve a short how-to video of their web app before it is made again. An AI planner will read the code and plan the video again from the description you write, and a browser will record it. From how the last attempt went, rewrite the description so the next one is better: name the exact place to start and what it ends on, say which parts matter and which to skip, and steer around steps that failed. Keep it to one to four plain sentences, in the words the app\'s users would use. Change only what helps; if the developer said what they want, do that first. Then list each change you made and why, in a short plain sentence each.',
            prompt: (new Redactor)->redact(implode("\n\n", array_filter([
                trim($wish) !== '' ? "<developer_wants>\n".trim($wish)."\n</developer_wants>" : 'The developer did not say what to change: improve what would help most.',
                "<current_description>\n{$video->prompt}\n</current_description>",
                'Style: '.($video->style['name'] ?? 'unknown').'. Target length: '.$video->target_seconds.' seconds'
                    .($video->duration_seconds ? '; the last video came out at '.round($video->duration_seconds).' seconds' : '').'.'
                    .($video->start_path ? " Start page: {$video->start_path}." : ''),
                $this->describePlan($video),
                $this->describeRun($video),
                $notes !== '' ? "<notes_from_the_developer>\n$notes\n</notes_from_the_developer>" : null,
                "<routes>\n".$this->code->routeList()."\n</routes>",
            ]))),
            schema: [
                'type' => 'object',
                'properties' => [
                    'prompt' => ['type' => 'string', 'description' => 'The improved description of the video'],
                    'start_path' => ['type' => 'string', 'description' => 'The path the video should start on, such as /dashboard; empty to keep it as it is'],
                    'target_seconds' => ['type' => 'integer', 'description' => "Suggested length in seconds, 10 to $maxSeconds"],
                    'changes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Each change made and why, one short sentence each'],
                ],
                'required' => ['prompt', 'start_path', 'target_seconds', 'changes'],
                'additionalProperties' => false,
            ],
            model: $model,
        );

        /** @var Turn $turn */
        $turn = $answer['turn'];
        $data = $answer['data'];
        $video->addUsage($turn->usage);

        if ($turn->stop === Turn::REFUSAL) {
            throw new PlanningFailed('The model declined to improve this description.');
        }
        if (empty($data['prompt'])) {
            throw new PlanningFailed('The model did not return a description. Try again.');
        }

        $start = (string) ($data['start_path'] ?? '');

        return [
            'prompt' => (string) $data['prompt'],
            'start_path' => str_starts_with($start, '/') ? $start : $video->start_path,
            'target_seconds' => max(10, min($maxSeconds, (int) ($data['target_seconds'] ?? $video->target_seconds))),
            'changes' => array_values(array_filter(array_map('strval', (array) ($data['changes'] ?? [])))),
            'cost' => $turn->usage->cost,
            'cost_known' => Pricing::known($turn->model),
        ];
    }

    /**
     * Describe the video's last plan: its steps, captions and cards.
     */
    protected function describePlan(Video $video): ?string
    {
        $steps = $video->plan['steps'] ?? [];
        if ($steps === []) {
            return 'There is no plan yet: the last attempt stopped before one was made.';
        }

        $lines = [];
        foreach (array_slice($steps, 0, 80) as $i => $step) {
            $what = match ($step['action'] ?? '') {
                'goto' => $step['url'] ?? '',
                'caption', 'card' => '"'.($step['title'] ?? '').'"'.(! empty($step['text']) ? ' — '.$step['text'] : ''),
                default => collect($step['target'] ?? [])->filter()->map(fn ($v, $k) => "$k: $v")->implode(', ').(! empty($step['value']) ? ' → "'.$step['value'].'"' : ''),
            };
            $lines[] = ($i + 1).'. '.($step['action'] ?? '?').' '.$what;
        }

        return "<last_plan>\n".implode("\n", $lines)."\n</last_plan>";
    }

    /**
     * Describe how the last attempt went, from the video's log, error and page problems.
     */
    protected function describeRun(Video $video): ?string
    {
        $log = collect($video->log ?? [])->pluck('message')
            ->filter(fn ($m) => preg_match('/failed|fix the plan|Recorded|Stopped|Ready|isn\'t working|could not/i', (string) $m))
            ->take(-20)->values()->all();
        $issues = array_map(fn ($i) => "{$i['path']}: {$i['problem']}".(! empty($i['detail']) ? " ({$i['detail']})" : ''), $video->issues ?? []);

        $parts = array_filter([
            $log ? "What happened:\n- ".implode("\n- ", $log) : null,
            $video->error ? "It ended with this error: {$video->error}" : null,
            $issues ? "Pages that did not work:\n- ".implode("\n- ", $issues) : null,
        ]);

        return $parts ? "<last_attempt>\n".implode("\n\n", $parts)."\n</last_attempt>" : null;
    }
}
