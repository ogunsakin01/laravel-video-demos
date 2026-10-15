<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Drivers\Turn;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Plan;

/**
 * Writes the voice-over for a plan that has none, such as when a voice is
 * added to a video and its plan is recorded again: one short request, much
 * cheaper than planning again. Its cost is added to the video's.
 */
class Narrator
{
    /**
     * Create a new narrator instance.
     */
    public function __construct(protected Drivers $drivers) {}

    /**
     * Get the plan with a spoken line on the steps worth narrating.
     *
     * @throws PlanningFailed When the model declines or returns nothing.
     */
    public function narrate(Plan $plan, Video $video): Plan
    {
        $steps = [];
        foreach ($plan->steps as $i => $step) {
            $what = match ($step['action'] ?? '') {
                'goto' => 'opens '.($step['url'] ?? ''),
                'caption', 'card' => ($step['action']).' "'.($step['title'] ?? '').'"'.(! empty($step['text']) ? ': '.$step['text'] : ''),
                default => ($step['action'] ?? '?').' '.collect($step['target'] ?? [])->filter()->implode(' ').(! empty($step['value']) ? ' "'.$step['value'].'"' : ''),
            };
            $steps[] = ($i + 1).". $what".(! empty($step['why']) ? " ({$step['why']})" : '');
        }

        $answer = $this->drivers->for($video->model)->structured(
            system: "You write the voice-over for a short how-to video of a web app. A narrator speaks one line during a step while the viewer watches it happen, in the style's tone.\n".VoiceLines::GUIDE."\n".VoiceLines::language($video),
            prompt: (new Redactor)->redact(implode("\n\n", array_filter([
                "<video>\n{$video->prompt}\n</video>",
                ! empty($video->style['direction']) ? "<style name=\"{$video->style['name']}\">\n{$video->style['direction']}\n</style>" : null,
                "<steps>\n".implode("\n", $steps)."\n</steps>",
                'Return the lines with the number of the step each is spoken during.',
            ]))),
            schema: [
                'type' => 'object',
                'properties' => [
                    'lines' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'step' => ['type' => 'integer', 'description' => 'The step number, from 1'],
                                'say' => ['type' => 'string', 'description' => 'What the narrator says during that step'],
                            ],
                            'required' => ['step', 'say'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['lines'],
                'additionalProperties' => false,
            ],
            model: $video->model,
        );

        /** @var Turn $turn */
        $turn = $answer['turn'];
        $video->addUsage($turn->usage, 'narrate');
        if ($turn->stop === Turn::REFUSAL || empty($answer['data']['lines'])) {
            throw new PlanningFailed('The model did not write a voice-over'.($turn->stop ? " (it stopped: {$turn->stop})" : '').'.'.(trim($turn->text) !== '' ? ' It answered: '.Str::limit(trim($turn->text), 300) : ''));
        }

        $data = $plan->toArray();
        foreach ($answer['data']['lines'] as $line) {
            $i = (int) ($line['step'] ?? 0) - 1;
            $say = trim((string) ($line['say'] ?? ''));
            if (isset($data['steps'][$i]) && $say !== '') {
                $data['steps'][$i]['say'] = $say;
            }
        }

        return Plan::fromArray($data);
    }
}
