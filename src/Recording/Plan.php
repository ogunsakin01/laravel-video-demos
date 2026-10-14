<?php

namespace Ogunsakin01\VideoDemo\Recording;

/**
 * The step list Claude writes and the recorder plays. Stored with each video,
 * so a video can be recorded again after the UI changes without asking
 * Claude again.
 */
final class Plan
{
    public const ACTIONS = ['goto', 'click', 'type', 'select', 'check', 'press', 'hover', 'scroll', 'wait', 'caption', 'card', 'pause'];

    /** Rough time each action takes on screen, in milliseconds, before pauses */
    protected const ACTION_MS = [
        'goto' => 1500, 'click' => 1300, 'type' => 1000, 'select' => 1400, 'check' => 1200,
        'press' => 400, 'hover' => 1000, 'scroll' => 900, 'wait' => 500,
    ];

    /** Characters typed per second by the recorder */
    protected const TYPING_PER_SECOND = 18;

    /** @param list<array<string, mixed>> $steps */
    public function __construct(
        public string $title,
        public string $summary,
        public array $steps,
    ) {}

    public const TARGET_FIELDS = ['role', 'name', 'label', 'placeholder', 'text', 'selector', 'frame'];

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['title'] ?? ''),
            (string) ($data['summary'] ?? ''),
            array_values(array_map(fn ($step) => self::normalise((array) $step), $data['steps'] ?? [])),
        );
    }

    /**
     * Empty values from the model ("", 0, []) mean "not set": the schema has
     * no nullable fields, to stay within Anthropic's limit on union types
     */
    protected static function normalise(array $step): array
    {
        foreach (['url', 'value', 'title', 'text', 'chapter'] as $field) {
            if (($step[$field] ?? null) === '') {
                $step[$field] = null;
            }
        }
        if (empty($step['ms'])) {
            $step['ms'] = null;
        }
        if (empty($step['only_on'])) {
            $step['only_on'] = null;
        }
        if (is_array($step['target'] ?? null)) {
            $target = array_map(fn ($v) => $v === '' ? null : $v, $step['target']);
            $step['target'] = array_filter($target, fn ($v) => $v !== null) === [] ? null : $target;
        }

        return $step;
    }

    public function toArray(): array
    {
        return ['title' => $this->title, 'summary' => $this->summary, 'steps' => $this->steps];
    }

    /** The steps a version plays: those without only_on, and those naming it */
    public function forDevice(string $device): self
    {
        $steps = array_values(array_filter(
            $this->steps,
            fn ($s) => empty($s['only_on']) || in_array($device, $s['only_on'], true),
        ));

        return new self($this->title, $this->summary, $steps);
    }

    /**
     * Estimated length of the finished video, in seconds. At $speed above 1
     * the recording is played back faster: actions take less time, while
     * captions, cards and pauses are held longer while recording, so they
     * keep their length.
     */
    public function estimatedSeconds(float $speed = 1): float
    {
        return array_sum(array_map(fn ($s) => $this->stepMs($s, $speed), $this->steps)) / 1000;
    }

    /**
     * Stretches or shrinks captions, cards and pauses so the video lands near
     * the target length. Actions keep their natural speed, and nothing is cut
     * to less than half or more than three times its planned time.
     */
    public function fitTo(int $targetSeconds, float $speed = 1): self
    {
        $flexibleMs = 0;
        $fixedMs = 0;
        foreach ($this->steps as $step) {
            $this->isFlexible($step) ? $flexibleMs += $this->stepMs($step, $speed) : $fixedMs += $this->stepMs($step, $speed);
        }

        if ($flexibleMs === 0) {
            return $this;
        }

        $factor = max(0.5, min(3.0, ($targetSeconds * 1000 - $fixedMs) / $flexibleMs));

        $steps = array_map(function ($step) use ($factor) {
            if ($this->isFlexible($step)) {
                $step['ms'] = (int) round($this->stepMs($step) * $factor);
            }

            return $step;
        }, $this->steps);

        return new self($this->title, $this->summary, $steps);
    }

    /** Chapters for the player, from the steps that start one */
    public function chapters(): array
    {
        $chapters = [];
        $elapsed = 0;
        foreach ($this->steps as $step) {
            if (! empty($step['chapter'])) {
                $chapters[] = ['title' => $step['chapter'], 'at' => round($elapsed / 1000, 1)];
            }
            $elapsed += $this->stepMs($step);
        }

        return $chapters;
    }

    protected function isFlexible(array $step): bool
    {
        return in_array($step['action'] ?? null, ['caption', 'card', 'pause'], true);
    }

    /** How long a step lasts in the finished video */
    protected function stepMs(array $step, float $speed = 1): int
    {
        $action = $step['action'] ?? 'pause';

        if ($this->isFlexible($step)) {
            return (int) ($step['ms'] ?? 2500);
        }

        $ms = self::ACTION_MS[$action] ?? 1000;
        if ($action === 'type') {
            $ms += (int) (strlen((string) ($step['value'] ?? '')) / self::TYPING_PER_SECOND * 1000);
        }

        // Actions play back faster; a pause the plan adds after one keeps its length
        return (int) ($ms / max($speed, 0.1)) + (int) ($step['ms'] ?? 0);
    }

    /**
     * JSON schema for the submit_plan tool, strict-mode compatible.
     *
     * No field is "something or null": Anthropic limits strict tool schemas
     * to 16 fields with union types, so an unused field is empty instead
     * ("", 0 or []) and fromArray() turns it back into null.
     */
    public static function schema(): array
    {
        $string = fn (string $description) => ['type' => 'string', 'description' => $description];

        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Short video title, as a user would search for it'],
                'summary' => ['type' => 'string', 'description' => 'One or two sentences on what the video shows'],
                'steps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                            'why' => ['type' => 'string', 'description' => 'What this step is for, in a few words. Not shown in the video.'],
                            'url' => $string('goto: a path such as /jobs/create. Empty for other actions.'),
                            'target' => [
                                'type' => 'object',
                                'description' => 'The element to act on. Fill in one way to find it, the way a user would see it, and leave the rest empty. All empty for actions without an element.',
                                'properties' => [
                                    'role' => $string('ARIA role: button, link, textbox, combobox, checkbox, tab, menuitem, heading…'),
                                    'name' => $string('Accessible name, used with role: the visible label or text'),
                                    'label' => $string('Form field label text'),
                                    'placeholder' => $string('Input placeholder text'),
                                    'text' => $string('Visible text of the element'),
                                    'selector' => $string('CSS selector, only when nothing above identifies it'),
                                    'frame' => $string('CSS selector of the iframe the element is inside, such as iframe[title="Secure card number input frame"]. Empty when it is on the page itself.'),
                                ],
                                'required' => self::TARGET_FIELDS,
                                'additionalProperties' => false,
                            ],
                            'value' => $string('type: the text; select: the option label; press: the key, such as Enter. Empty otherwise.'),
                            'title' => $string('caption or card: the heading. Empty otherwise.'),
                            'text' => $string('caption or card: one short sentence under the heading. Empty otherwise.'),
                            'ms' => ['type' => 'integer', 'description' => 'caption, card, pause: how long it shows, in milliseconds. Other actions: an extra pause afterwards. 0 for the default.'],
                            'chapter' => $string('Starts a chapter with this name at this step. Empty otherwise.'),
                            'only_on' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'enum' => Devices::keys()],
                                'description' => 'Run this step only on these versions, for example ["mobile"] to open the menu that small screens hide navigation behind. Empty runs it on every version.',
                            ],
                        ],
                        'required' => ['action', 'why', 'url', 'target', 'value', 'title', 'text', 'ms', 'chapter', 'only_on'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['title', 'summary', 'steps'],
            'additionalProperties' => false,
        ];
    }
}
