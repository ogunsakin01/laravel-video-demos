<?php

namespace Ogunsakin01\VideoDemo\Recording;

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
    ) {}

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
        );
    }

    /** True when a step failed in the app, so a corrected plan may work */
    public function canRepair(): bool
    {
        return ! $this->ok && $this->failedStep !== null;
    }

    public function summary(): string
    {
        $on = $this->device ? ' ('.Devices::get($this->device)['label'].')' : '';

        return $this->ok
            ? sprintf('Recorded %.1f seconds%s', $this->seconds, $on)
            : ($this->failedStep ? "Step {$this->failedStep} failed$on: {$this->error}" : "Recording failed$on: {$this->error}");
    }

    /** The failure as Claude sees it when asked to fix the plan */
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
