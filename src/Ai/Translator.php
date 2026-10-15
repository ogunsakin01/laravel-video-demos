<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Drivers\Turn;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\Support\Language;

/**
 * Turns a video's plan into another language: its title, summary, captions,
 * cards, chapters, voice-over and typed sample values, in one short request,
 * much cheaper than planning again. Targets are left as they are; when the
 * app's own labels are translated too, fixStep() finds the element again on
 * the translated page. Costs are added to the video's.
 */
class Translator
{
    /**
     * The fields of a step that are words shown or spoken in the video.
     */
    protected const FIELDS = ['title', 'text', 'chapter', 'say'];

    /**
     * Create a new translator instance.
     */
    public function __construct(protected Drivers $drivers) {}

    /**
     * Get the plan with its words in the video's language.
     *
     * @throws PlanningFailed When the model declines or returns nothing.
     */
    public function translate(Plan $plan, Video $video, string $from): Plan
    {
        $to = $video->languageCode();
        $texts = $this->texts($plan);
        if ($texts === []) {
            return $plan;
        }

        $answer = $this->drivers->for($video->model)->structured(
            system: implode("\n", [
                'You translate the words of a short how-to video of a web app from '.Language::name($from).' into '.Language::name($to).", code $to.",
                'The video shows title cards and captions over the app, and a narrator may speak a line during a step. Write each the way people naturally write and speak '.Language::name($to).', not as a word-for-word translation, and keep it about as long as the original: captions must stay short.',
                'Keep the names of products, companies, people and places, and anything in quotes that names a button, link or field in the app, unless the app shows it translated. Keep the tone of the original.',
                'Typed values (field "value") are sample text typed into the app, such as a job title or a message; translate them so they suit the app\'s users in that language, but keep emails, numbers, codes and dates as they are.',
                VoiceLines::GUIDE,
            ]),
            prompt: (new Redactor)->redact(implode("\n\n", array_filter([
                "<video>\n{$video->prompt}\n</video>",
                ! empty($video->style['direction']) ? "<style name=\"{$video->style['name']}\">\n{$video->style['direction']}\n</style>" : null,
                "<texts>\n".json_encode(array_map(fn ($id, $text) => ['id' => $id, 'text' => $text], array_keys($texts), $texts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n</texts>",
                'Return every text, translated, with its id.',
            ]))),
            schema: [
                'type' => 'object',
                'properties' => [
                    'texts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string', 'description' => 'The id of the text, as given'],
                                'text' => ['type' => 'string', 'description' => 'The text in '.Language::name($to)],
                            ],
                            'required' => ['id', 'text'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['texts'],
                'additionalProperties' => false,
            ],
            model: $video->model,
        );

        /** @var Turn $turn */
        $turn = $answer['turn'];
        $video->addUsage($turn->usage, 'translate');
        if ($turn->stop === Turn::REFUSAL || empty($answer['data']['texts'])) {
            throw new PlanningFailed('The model did not translate the video'.($turn->stop ? " (it stopped: {$turn->stop})" : '').'.'.(trim($turn->text) !== '' ? ' It answered: '.Str::limit(trim($turn->text), 300) : ''));
        }

        $translated = [];
        foreach ($answer['data']['texts'] as $item) {
            $text = trim((string) ($item['text'] ?? ''));
            if (isset($texts[$item['id'] ?? '']) && $text !== '') {
                $translated[$item['id']] = $text;
            }
        }

        $data = $plan->toArray();
        $data['title'] = $translated['title'] ?? $data['title'];
        $data['summary'] = $translated['summary'] ?? $data['summary'];
        foreach ($data['steps'] as $i => &$step) {
            foreach ([...self::FIELDS, 'value'] as $field) {
                if (isset($translated["$i.$field"])) {
                    $step[$field] = $translated["$i.$field"];
                }
            }
            // The new lines are spoken again, so their old lengths no longer apply.
            unset($step['voice_ms']);
        }
        unset($data['chapters']);

        return Plan::fromArray($data);
    }

    /**
     * Find the element a step uses on the translated page, after its old
     * target wasn't found there, and get the plan with the step fixed: a new
     * target, and for a select the option's label as the page shows it.
     *
     * @throws PlanningFailed When the model declines or finds nothing.
     */
    public function fixStep(Plan $plan, Video $video, RecordingResult $failure): Plan
    {
        // The failed step's place in the whole plan: steps are numbered as its version plays them.
        $indexes = array_keys(array_filter($plan->steps, fn ($s) => ! $failure->device || empty($s['only_on']) || in_array($failure->device, $s['only_on'], true)));
        $index = $indexes[($failure->failedStep ?? 0) - 1] ?? null;
        $step = $index !== null ? $plan->steps[$index] : null;
        if ($step === null) {
            throw new PlanningFailed('There is no step to fix.');
        }

        $answer = $this->drivers->for($video->model)->structured(
            system: 'A how-to video of a web app was planned with the app in one language, and is now recorded with the app in '.Language::name($video->languageCode()).'. A step\'s target, which finds the element it uses, no longer matches, usually because the app\'s labels are translated. Find the same element on the page as it is now, identified the way a user sees it: role and name together, else a field label, placeholder or visible text, and a CSS selector only when nothing visible identifies it. For a select, also give the option\'s label as the page shows it.',
            prompt: (new Redactor)->redact(implode("\n\n", array_filter([
                "<step>\n".json_encode(array_intersect_key($step, array_flip(['action', 'target', 'value', 'why'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n</step>",
                "It failed with: {$failure->error}",
                $failure->snapshot ? "The page's accessibility tree now:\n<page>\n{$failure->snapshot}\n</page>" : null,
                'Return the target that finds this element now, with the fields you don\'t use empty, and the value (the option label for a select, otherwise the value as it was).',
            ]))),
            schema: [
                'type' => 'object',
                'properties' => [
                    'target' => [
                        'type' => 'object',
                        'properties' => array_fill_keys(Plan::TARGET_FIELDS, ['type' => 'string']),
                        'required' => Plan::TARGET_FIELDS,
                        'additionalProperties' => false,
                    ],
                    'value' => ['type' => 'string'],
                ],
                'required' => ['target', 'value'],
                'additionalProperties' => false,
            ],
            model: $video->model,
        );

        /** @var Turn $turn */
        $turn = $answer['turn'];
        $video->addUsage($turn->usage, 'translate');
        $target = array_filter((array) ($answer['data']['target'] ?? []), fn ($v) => is_string($v) && trim($v) !== '');
        if ($turn->stop === Turn::REFUSAL || array_diff_key($target, ['frame' => true]) === []) {
            throw new PlanningFailed("Step {$failure->failedStep} couldn't be found on the translated page: {$failure->error}");
        }

        $data = $plan->toArray();
        $data['steps'][$index]['target'] = array_combine(Plan::TARGET_FIELDS, array_map(fn ($f) => isset($target[$f]) ? trim($target[$f]) : null, Plan::TARGET_FIELDS));
        if (($step['action'] ?? '') === 'select' && trim((string) ($answer['data']['value'] ?? '')) !== '') {
            $data['steps'][$index]['value'] = trim((string) $answer['data']['value']);
        }

        return Plan::fromArray($data);
    }

    /**
     * Get the words to translate, keyed by where they go: "title",
     * "summary", or a step's index and field, such as "3.say".
     *
     * @return array<string, string>
     */
    protected function texts(Plan $plan): array
    {
        $texts = array_filter(['title' => $plan->title, 'summary' => $plan->summary], fn ($t) => trim($t) !== '');
        foreach ($plan->steps as $i => $step) {
            foreach (self::FIELDS as $field) {
                if (is_string($step[$field] ?? null) && trim($step[$field]) !== '') {
                    $texts["$i.$field"] = $step[$field];
                }
            }
            if (($step['action'] ?? '') === 'type' && is_string($step['value'] ?? null) && trim($step['value']) !== '') {
                $texts["$i.value"] = $step['value'];
            }
        }

        return $texts;
    }
}
