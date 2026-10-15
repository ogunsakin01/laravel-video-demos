<?php

namespace Ogunsakin01\VideoDemo\Recording;

/**
 * The step list the AI model writes and the recorder plays. It is stored with
 * each video, so a video can be recorded again after the UI changes without
 * asking the AI model again.
 */
final class Plan
{
    public const ACTIONS = ['goto', 'click', 'type', 'select', 'check', 'press', 'hover', 'scroll', 'wait', 'caption', 'card', 'pause'];

    /**
     * Rough time each action takes on screen, in milliseconds, before pauses.
     */
    protected const ACTION_MS = [
        'goto' => 1500, 'click' => 1300, 'type' => 1000, 'select' => 1400, 'check' => 1200,
        'press' => 400, 'hover' => 1000, 'scroll' => 900, 'wait' => 500,
    ];

    /**
     * Characters typed per second by the recorder.
     */
    protected const TYPING_PER_SECOND = 18;

    /**
     * Create a new plan instance.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    public function __construct(
        public string $title,
        public string $summary,
        public array $steps,
    ) {}

    public const TARGET_FIELDS = ['role', 'name', 'label', 'placeholder', 'text', 'selector', 'frame'];

    /**
     * Create a plan from the AI model's submission or a video's stored plan.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['title'] ?? ''),
            (string) ($data['summary'] ?? ''),
            array_values(array_map(fn ($step) => self::normalise((array) $step), $data['steps'] ?? [])),
        );
    }

    /**
     * Turn the empty values the model sends ("", 0, []) into null.
     *
     * The schema has no nullable fields, to stay within Anthropic's limit on
     * union types, so an empty value means "not set".
     */
    protected static function normalise(array $step): array
    {
        foreach (['url', 'value', 'title', 'text', 'chapter', 'say'] as $field) {
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

    /**
     * Get the plan as an array.
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'summary' => $this->summary, 'steps' => $this->steps];
    }

    /**
     * Get the plan as one version plays it: the steps without only_on, and
     * those naming the version.
     */
    public function forDevice(string $device): self
    {
        $steps = array_values(array_filter(
            $this->steps,
            fn ($s) => empty($s['only_on']) || in_array($device, $s['only_on'], true),
        ));

        return new self($this->title, $this->summary, $steps);
    }

    /**
     * Get the plan with each spoken step held, while recording, until its
     * line is finished: the line's length plus a short gap, times the speed
     * the video will play at. $voice gives each line's length in seconds.
     *
     * @param  array<string, float>  $voice
     */
    public function withHolds(array $voice, float $speed = 1): self
    {
        $steps = array_map(function ($step) use ($voice, $speed) {
            $say = trim((string) ($step['say'] ?? ''));
            if ($say !== '' && isset($voice[$say])) {
                $step['hold_ms'] = (int) round(($voice[$say] + 0.5) * 1000 * $speed);
            }

            return $step;
        }, $this->steps);

        return new self($this->title, $this->summary, $steps);
    }

    /**
     * Get the plan's spoken lines, in order.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) ($s['say'] ?? '')), $this->steps))));
    }

    /**
     * Get the pages the plan opens with goto, so they can be checked before
     * recording.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($step) => ($step['action'] ?? null) === 'goto' ? ($step['url'] ?? null) : null,
            $this->steps,
        ))));
    }

    /**
     * Get the estimated length of the finished video, in seconds.
     *
     * At $speed above 1 the recording is played back faster: actions take
     * less time, while captions, cards and pauses are held longer while
     * recording, so they keep their length.
     */
    public function estimatedSeconds(float $speed = 1): float
    {
        return array_sum(array_map(fn ($s) => $this->stepMs($s, $speed), $this->steps)) / 1000;
    }

    /**
     * Stretch or shrink captions, cards and pauses so the video lands near the
     * target length.
     *
     * Actions keep their natural speed, and nothing is cut to less than half
     * or stretched to more than three times its planned time.
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

        // Captions are never stretched far past what they need: a video a
        // little shorter than its target beats one that lingers.
        $factor = max(0.5, min(1.4, ($targetSeconds * 1000 - $fixedMs) / $flexibleMs));

        $steps = array_map(function ($step) use ($factor) {
            if ($this->isFlexible($step)) {
                $step['ms'] = (int) round($this->stepMs($step) * $factor);
            }

            return $step;
        }, $this->steps);

        return new self($this->title, $this->summary, $steps);
    }

    /**
     * Get the chapters for the player, from the steps that start one.
     */
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

    /**
     * Determine if a step's length can change to fit the target length.
     */
    protected function isFlexible(array $step): bool
    {
        return in_array($step['action'] ?? null, ['caption', 'card', 'pause'], true);
    }

    /**
     * Get how long a step lasts in the finished video, in milliseconds.
     */
    protected function stepMs(array $step, float $speed = 1): int
    {
        // A step lasts at least as long as its spoken line.
        return max($this->actionMs($step, $speed), self::speechMs($step));
    }

    /**
     * Get how long a step's spoken line lasts, in milliseconds, once its clip
     * is made. Lines of a video without a voice take no time.
     */
    public static function speechMs(array $step): int
    {
        return empty($step['say']) ? 0 : (int) ($step['voice_ms'] ?? 0);
    }

    /**
     * Get the plan without its clips' lengths, for a video recorded without
     * a voice. Its lines are kept, to be spoken if a voice is chosen later.
     */
    public function withoutVoice(): self
    {
        return new self($this->title, $this->summary, array_map(function ($step) {
            unset($step['voice_ms']);

            return $step;
        }, $this->steps));
    }

    /**
     * Get how long a step's action lasts in the finished video, in milliseconds.
     */
    protected function actionMs(array $step, float $speed = 1): int
    {
        $action = $step['action'] ?? 'pause';

        if ($this->isFlexible($step)) {
            return (int) ($step['ms'] ?? 2500);
        }

        $ms = self::ACTION_MS[$action] ?? 1000;
        if ($action === 'type') {
            $ms += (int) (strlen((string) ($step['value'] ?? '')) / self::TYPING_PER_SECOND * 1000);
        }

        // Actions play back faster; a pause the plan adds after one keeps its length.
        return (int) ($ms / max($speed, 0.1)) + (int) ($step['ms'] ?? 0);
    }

    /**
     * Get the JSON schema for the submit_plan tool, compatible with strict mode.
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
                            'say' => $string('Voice-over: what the narrator says during this step, as one short spoken sentence. Empty for silence, and always empty when the video has no voice.'),
                            'only_on' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'enum' => Devices::keys()],
                                'description' => 'Run this step only on these versions, for example ["mobile"] to open the menu that small screens hide navigation behind. Empty runs it on every version.',
                            ],
                        ],
                        'required' => ['action', 'why', 'url', 'target', 'value', 'title', 'text', 'ms', 'chapter', 'say', 'only_on'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['title', 'summary', 'steps'],
            'additionalProperties' => false,
        ];
    }
}
