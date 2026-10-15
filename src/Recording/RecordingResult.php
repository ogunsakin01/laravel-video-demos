<?php

namespace Ogunsakin01\VideoDemo\Recording;

/**
 * The outcome of recording one version of a video, read from the recorder's
 * result.json.
 */
final readonly class RecordingResult
{
    public function __construct(
        public bool $ok,
        public ?string $video,
        public float $seconds,
        public float $trimSeconds,
        public array $chapters,
        public ?int $failedStep,
        public ?string $error,
        public ?string $url,
        public ?string $title,
        public ?string $snapshot,
        public ?string $device = null,
        public ?array $appError = null,
        public array $focus = [],
        public array $spoken = [],
        public ?string $errorFull = null,
        public ?string $screenshot = null,
        public array $browserLog = [],
        public ?Timeline $timeline = null,
    ) {}

    /**
     * Create a result from the recorder's result.json, with times in seconds.
     */
    public static function fromArray(array $r, ?string $device = null): self
    {
        return new self(
            (bool) ($r['ok'] ?? false),
            $r['video'] ?? null,
            round(($r['durationMs'] ?? 0) / 1000, 2),
            round(($r['trimMs'] ?? 0) / 1000, 2),
            $r['chapters'] ?? [],
            $r['failedStep'] ?? null,
            $r['error'] ?? null,
            $r['url'] ?? null,
            $r['title'] ?? null,
            $r['snapshot'] ?? null,
            $device,
            $r['appError'] ?? null,
            $r['focus'] ?? [],
            self::spoken($r['lines'] ?? [], $r['starts'] ?? []),
            $r['errorFull'] ?? null,
            $r['screenshot'] ?? null,
            $r['browserLog'] ?? [],
            isset($r['timeline']) ? Timeline::fromArray($r['timeline']) : null,
        );
    }

    /**
     * Pair each spoken line with when its step started, in seconds of the
     * finished video, so its clip can be placed there.
     *
     * @param  list<?string>  $lines
     * @param  list<float>  $starts
     * @return list<array{text: string, at: float}>
     */
    protected static function spoken(array $lines, array $starts): array
    {
        $spoken = [];
        foreach ($lines as $i => $line) {
            if (is_string($line) && trim($line) !== '' && isset($starts[$i])) {
                $spoken[] = ['text' => trim($line), 'at' => (float) $starts[$i]];
            }
        }

        return $spoken;
    }

    /**
     * Determine if a step failed in a way a corrected plan may fix.
     *
     * Not when the app itself broke (a server or JavaScript error), since no
     * plan fixes that.
     */
    public function canRepair(): bool
    {
        return ! $this->ok && $this->failedStep !== null && $this->appError === null;
    }

    /**
     * Get the app's own error as an issue to show the developer.
     */
    public function issue(): ?array
    {
        if (! $this->appError) {
            return null;
        }

        return [
            'path' => parse_url((string) ($this->appError['url'] ?? $this->url), PHP_URL_PATH) ?: (string) $this->url,
            'problem' => $this->appError['kind'] === 'server'
                ? "The app returned HTTP {$this->appError['status']} during step {$this->failedStep}"
                : "The page threw a JavaScript error during step {$this->failedStep}",
            'status' => $this->appError['status'] ?? null,
            'url' => $this->appError['url'] ?? $this->url,
            'detail' => $this->appError['detail'] ?? null,
        ];
    }

    /**
     * Get a one-line summary for the video's log.
     */
    public function summary(): string
    {
        $on = $this->device ? ' ('.Devices::get($this->device)['label'].')' : '';

        return $this->ok
            ? sprintf('Recorded %.1f seconds%s', $this->seconds, $on)
            : ($this->failedStep ? "Step {$this->failedStep} failed$on: {$this->error}" : "Recording failed$on: {$this->error}");
    }

    /**
     * Describe the failure for the AI model when it is asked to fix the plan.
     */
    public function describeForRepair(): string
    {
        return implode("\n\n", array_filter([
            "Step {$this->failedStep} failed while recording".($this->device ? ' the '.Devices::describe($this->device).' version' : '').": {$this->error}",
            "The browser was on {$this->url}".($this->title ? " (page title: {$this->title})" : '').'.',
            $this->snapshot ? "The page's accessibility tree at that moment:\n<page>\n{$this->snapshot}\n</page>" : null,
            'Steps are numbered as that version plays them, leaving out steps whose only_on excludes it. Steps before this one worked. Read more code if you need to, then submit a corrected plan.',
        ]));
    }
}
