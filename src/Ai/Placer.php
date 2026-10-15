<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Drivers\Turn;
use Ogunsakin01\VideoDemo\Models\Video;

/**
 * Suggests where in the app a finished video should be shown, so users find
 * it when they need it: one short request over the sections of the pages the
 * video visits. Its cost is added to the video's.
 */
class Placer
{
    /**
     * Where a video goes against its section: at the top inside it, or just before or after it.
     */
    public const POSITIONS = ['top', 'before', 'after'];

    /**
     * Create a new placer instance.
     */
    public function __construct(protected Drivers $drivers) {}

    /**
     * Pick up to three spots from the mapped pages.
     *
     * @param  list<array{path: string, title: string, sections: list<array{id: int, selector: string, kind: string, heading: string, text: string, box: array{x: int, y: int, w: int, h: int}}>}>  $pages
     * @return list<array{path: string, section: array<string, mixed>, position: string, label: string, why: string}>
     *
     * @throws PlanningFailed When the model declines or suggests nothing that exists.
     */
    public function place(Video $video, array $pages, string $model): array
    {
        $listing = [];
        foreach ($pages as $page) {
            $lines = array_map(fn ($s) => "  [{$s['id']}] {$s['kind']}".($s['heading'] !== '' ? " \"{$s['heading']}\"" : '')
                ." · {$s['box']['w']}×{$s['box']['h']} at y {$s['box']['y']} · {$s['text']}", $page['sections']);
            $listing[] = "<page path=\"{$page['path']}\" title=\"{$page['title']}\">\n".implode("\n", $lines)."\n</page>";
        }

        $answer = $this->drivers->for($model)->structured(
            system: 'You suggest where in a web app a short how-to video should be shown, so the people who need it find it at the moment they need it. You get the sections of the pages the video visits. Pick the places a user would be when they want this help: the top of the page or form where the task starts, an empty state that the task fills, a help or tips panel beside the task. Avoid navigation bars, footers, page headers that every page shares, and tiny elements. Explain each pick from the user\'s point of view, in one plain sentence.',
            prompt: (new Redactor)->redact(implode("\n\n", array_filter([
                '<video title="'.($video->title ?: $video->plan['title'] ?? '')."\">\n".trim(($video->plan['summary'] ?? '')."\n".$video->prompt)."\n</video>",
                'It is for: '.($video->record_as_label ?: 'any user').'.',
                implode("\n\n", $listing),
                'Suggest two or three spots, best first, at least one on the page where the task starts. Use each page\'s path and a section number from its list.',
            ]))),
            schema: [
                'type' => 'object',
                'properties' => [
                    'spots' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'The page path, as listed'],
                                'section' => ['type' => 'integer', 'description' => 'The section number on that page'],
                                'position' => ['type' => 'string', 'enum' => self::POSITIONS, 'description' => 'top: first thing inside the section; before or after: next to it'],
                                'label' => ['type' => 'string', 'description' => 'A short name for the spot, such as "Above the job form"'],
                                'why' => ['type' => 'string', 'description' => 'One sentence: why users would want the video there'],
                            ],
                            'required' => ['path', 'section', 'position', 'label', 'why'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['spots'],
                'additionalProperties' => false,
            ],
            model: $model,
        );

        /** @var Turn $turn */
        $turn = $answer['turn'];
        $video->addUsage($turn->usage, 'spots');
        if ($turn->stop === Turn::REFUSAL) {
            throw new PlanningFailed('The model declined to suggest where the video could go.');
        }

        $byPath = collect($pages)->keyBy('path');
        $spots = [];
        foreach ($answer['data']['spots'] ?? [] as $pick) {
            $section = collect($byPath[$pick['path'] ?? '']['sections'] ?? [])->firstWhere('id', (int) ($pick['section'] ?? 0));
            if (! $section || count($spots) >= 3) {
                continue;
            }
            $spots[] = [
                'path' => $pick['path'],
                'section' => $section,
                // Beside a whole page's wrapper, the card would sit outside the page's margins.
                'position' => in_array($pick['position'] ?? '', self::POSITIONS, true) && ! in_array($section['kind'], ['main', 'header', 'footer', 'nav'], true) ? $pick['position'] : 'top',
                'label' => trim((string) ($pick['label'] ?? '')) ?: ($section['heading'] ?: 'On '.$pick['path']),
                'why' => trim((string) ($pick['why'] ?? '')),
            ];
        }

        if ($spots === []) {
            throw new PlanningFailed('The model did not suggest a spot that is on the pages. Try again.');
        }

        return $spots;
    }

    /**
     * Pick a spot without AI: the top of the main content of the first page.
     *
     * @param  list<array{path: string, title: string, sections: list<array<string, mixed>>}>  $pages
     * @return list<array{path: string, section: array<string, mixed>, position: string, label: string, why: string}>
     */
    public static function fallback(array $pages): array
    {
        foreach ($pages as $page) {
            $sections = collect($page['sections']);
            $section = $sections->first(fn ($s) => in_array($s['kind'], ['main', 'empty state'], true))
                ?? $sections->first(fn ($s) => ! in_array($s['kind'], ['nav', 'header', 'footer'], true));
            if ($section) {
                return [[
                    'path' => $page['path'],
                    'section' => $section,
                    'position' => 'top',
                    'label' => 'Top of '.($page['title'] ?: $page['path']),
                    'why' => 'People doing this task start on this page, so the video is in front of them when they begin.',
                ]];
            }
        }

        return [];
    }
}
