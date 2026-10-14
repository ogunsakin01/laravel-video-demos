<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Drivers\Turn;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoIdea;
use Ogunsakin01\VideoDemo\Models\VideoStyle;

/**
 * Suggests which videos to make first, from the app's routes, each ready to open in the new-video form.
 * The ideas are saved, so asking again adds new ones and earlier ideas aren't paid for twice.
 */
class Suggester
{
    /**
     * Create a new suggester instance.
     */
    public function __construct(protected Drivers $drivers, protected CodeTools $code) {}

    /**
     * Ask the model for new video ideas and store them, or replace the unused ones when $replace is true.
     *
     * @return array{suggestions: list<array{id: int, title: string, prompt: string, why: string, start_path: ?string, target_seconds: int, style: string}>, cost: float, cost_known: bool, model: string, at: string}
     */
    public function suggest(?string $model = null, bool $replace = false): array
    {
        if ($replace) {
            // Ideas already made into videos stay, as a record.
            VideoIdea::whereNull('video_id')->delete();
        }
        $kept = VideoIdea::query()->pluck('title');

        $model ??= Pricing::defaultModel();
        $maxSeconds = (int) config('video-demo.limits.max_seconds', 300);
        $styles = VideoStyle::options();
        $notes = trim((string) config('video-demo.ai.notes'));
        $made = Video::query()->latest()->limit(50)->get()->map(fn (Video $v) => '- '.($v->title ?: $v->prompt))->implode("\n");

        $answer = $this->drivers->for($model)->structured(
            system: 'You plan a set of short how-to videos for a web app, the ones its users most need: getting started, the core tasks, and things people get stuck on. Read the routes to see what the app does. Suggest videos the routes show are possible; do not invent features. Describe each the way a user would ask about it, in one or two plain sentences: who does it, where it starts and what it ends with.',
            prompt: implode("\n\n", array_filter([
                'Suggest up to 8 videos, most useful first.',
                "<styles>\n".$styles->map(fn (VideoStyle $s) => "- {$s->key}: {$s->name}. {$s->direction}")->implode("\n")."\n</styles>\nPick the style that suits each video, by its key.",
                $made !== '' ? "<videos_already_made>\n$made\n</videos_already_made>\nDon't suggest these again." : null,
                $kept->isNotEmpty() ? "<ideas_already_suggested>\n".$kept->map(fn ($t) => "- $t")->implode("\n")."\n</ideas_already_suggested>\nSuggest different videos from these." : null,
                $notes !== '' ? "<notes_from_the_developer>\n$notes\n</notes_from_the_developer>" : null,
                "<routes>\n".$this->code->routeList()."\n</routes>",
            ])),
            schema: [
                'type' => 'object',
                'properties' => [
                    'suggestions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'description' => 'Short title, as a user would search for it'],
                                'prompt' => ['type' => 'string', 'description' => 'The description of the video'],
                                'why' => ['type' => 'string', 'description' => 'One short sentence: why users need this video'],
                                'start_path' => ['type' => 'string', 'description' => 'The path the video starts on, such as /dashboard; empty if unsure'],
                                'target_seconds' => ['type' => 'integer', 'description' => "Suggested length in seconds, 30 to $maxSeconds"],
                                'style' => ['type' => 'string', 'enum' => $styles->pluck('key')->values()->all()],
                            ],
                            'required' => ['title', 'prompt', 'why', 'start_path', 'target_seconds', 'style'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['suggestions'],
                'additionalProperties' => false,
            ],
            model: $model,
        );

        /** @var Turn $turn */
        $turn = $answer['turn'];

        if ($turn->stop === Turn::REFUSAL) {
            throw new PlanningFailed('The model declined to suggest videos.');
        }

        $suggestions = array_values(array_filter(array_map(fn ($s) => [
            'title' => (string) ($s['title'] ?? ''),
            'prompt' => (string) ($s['prompt'] ?? ''),
            'why' => (string) ($s['why'] ?? ''),
            'start_path' => str_starts_with((string) ($s['start_path'] ?? ''), '/') ? $s['start_path'] : null,
            'target_seconds' => max(10, min($maxSeconds, (int) ($s['target_seconds'] ?? 60))),
            'style' => $styles->contains('key', $s['style'] ?? null) ? $s['style'] : $styles->first()?->key,
        ], $answer['data']['suggestions'] ?? []), fn ($s) => $s['prompt'] !== ''));

        $seen = $kept->map(fn ($t) => Str::lower(trim($t)))->all();
        $suggestions = array_values(array_filter(array_slice($suggestions, 0, 8), function ($s) use (&$seen) {
            $key = Str::lower(trim($s['title']));
            if (in_array($key, $seen, true)) {
                return false;
            }
            $seen[] = $key;

            return true;
        }));

        if ($suggestions === []) {
            throw new PlanningFailed('The model did not suggest any new videos. Try again, or replace all the ideas.');
        }

        $share = $turn->usage->cost / count($suggestions);
        $saved = array_map(fn ($s) => VideoIdea::create([...$s, 'model' => $turn->model, 'cost' => $share])->only(['id', ...array_keys($s)]), $suggestions);

        return [
            'suggestions' => $saved,
            'cost' => $turn->usage->cost,
            'cost_known' => Pricing::known($turn->model),
            'model' => $turn->model,
            'at' => now()->toIso8601String(),
        ];
    }
}
