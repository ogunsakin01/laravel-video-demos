<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Illuminate\Support\Facades\Cache;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Drivers\Turn;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoStyle;

/**
 * Suggests which videos to make first, from the app's routes: the flows new
 * users most need, each ready to open in the new-video form. One short
 * request, kept until the next time suggestions are asked for.
 */
class Suggester
{
    protected const CACHE_KEY = 'video-demo.suggestions';

    public function __construct(protected Drivers $drivers, protected CodeTools $code) {}

    /**
     * @return array{suggestions: list<array{title: string, prompt: string, why: string, start_path: ?string, target_seconds: int, style: string}>, cost: float, cost_known: bool, model: string, at: string}
     */
    public function suggest(?string $model = null): array
    {
        $model ??= config('video-demo.ai.model');
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

        if ($suggestions === []) {
            throw new PlanningFailed('The model did not suggest any videos. Try again.');
        }

        $result = [
            'suggestions' => array_slice($suggestions, 0, 8),
            'cost' => $turn->usage->cost,
            'cost_known' => Pricing::known($turn->model),
            'model' => $turn->model,
            'at' => now()->toIso8601String(),
        ];

        Cache::forever(self::CACHE_KEY, $result);

        return $result;
    }

    /** The last suggestions, if any */
    public function latest(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }
}
