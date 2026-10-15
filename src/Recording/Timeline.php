<?php

namespace Ogunsakin01\VideoDemo\Recording;

/**
 * How a recording becomes the video: the parts that are kept, each played at
 * the video's speed, with most of the time spent waiting on the app cut out.
 * A click then goes straight to its result, the way it feels in a fast app,
 * while everything a person does plays at its own pace.
 *
 * Times are in ms since the recording started (after the part trimmed from
 * the start), except where they are seconds of the finished video.
 */
final readonly class Timeline
{
    /**
     * How much of a wait is kept before the cut: the press landing.
     */
    public const KEEP_BEFORE_MS = 150;

    /**
     * How much of a wait is kept after the cut: the result appearing.
     */
    public const KEEP_AFTER_MS = 250;

    /**
     * Waits shorter than this are kept whole.
     */
    public const SHORTEST_CUT_MS = 600;

    /**
     * @param  list<array{0: float, 1: float}>  $keep  The parts kept, [from, to] in ms of the recording.
     */
    public function __construct(public array $keep, public float $speed = 1) {}

    /**
     * Build the timeline for a recording of $durationMs, cutting the waits
     * except while a line of the voice-over is being spoken.
     *
     * @param  list<array{0: float, 1: float}>  $idle
     * @param  list<array{0: float, 1: float}>  $speaking
     */
    public static function build(float $durationMs, float $speed = 1, array $idle = [], array $speaking = []): self
    {
        $cuts = [];
        foreach (self::merge($idle) as [$from, $to]) {
            foreach (self::subtract([$from + self::KEEP_BEFORE_MS, $to - self::KEEP_AFTER_MS], self::merge($speaking)) as [$a, $b]) {
                if ($b - $a >= self::SHORTEST_CUT_MS - self::KEEP_BEFORE_MS - self::KEEP_AFTER_MS) {
                    $cuts[] = [$a, $b];
                }
            }
        }

        $keep = [];
        $at = 0.0;
        foreach ($cuts as [$a, $b]) {
            if ($a > $at) {
                $keep[] = [$at, $a];
            }
            $at = max($at, $b);
        }
        if ($durationMs > $at) {
            $keep[] = [$at, $durationMs];
        }

        return new self($keep ?: [[0.0, $durationMs]], max(0.1, $speed));
    }

    /**
     * Whether anything is cut, so the video needs editing beyond its speed.
     */
    public function hasCuts(): bool
    {
        return count($this->keep) > 1 || ($this->keep[0][0] ?? 0) > 0;
    }

    /**
     * Get where a moment of the recording falls in the finished video, in
     * seconds. A moment inside a cut lands where the cut is.
     */
    public function at(float $ms): float
    {
        $out = 0.0;
        foreach ($this->keep as [$from, $to]) {
            if ($ms <= $from) {
                break;
            }
            $out += min($ms, $to) - $from;
        }

        return round($out / 1000 / $this->speed, 3);
    }

    /**
     * Get the length of the finished video, in seconds.
     */
    public function seconds(): float
    {
        return round(array_sum(array_map(fn ($k) => $k[1] - $k[0], $this->keep)) / 1000 / $this->speed, 2);
    }

    /**
     * Get the timeline as plain data, for result.json and the encoder.
     *
     * @return array{keep: list<array{0: float, 1: float}>, speed: float}
     */
    public function toArray(): array
    {
        return ['keep' => $this->keep, 'speed' => $this->speed];
    }

    /**
     * Create a timeline from toArray()'s data.
     *
     * @param  array{keep?: list<array{0: float, 1: float}>, speed?: float}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['keep'] ?? [], (float) ($data['speed'] ?? 1));
    }

    /**
     * Sort intervals and join the ones that touch or overlap.
     *
     * @param  list<array{0: float, 1: float}>  $intervals
     * @return list<array{0: float, 1: float}>
     */
    protected static function merge(array $intervals): array
    {
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$from, $to]) {
            if ($to <= $from) {
                continue;
            }
            if ($merged && $from <= $merged[array_key_last($merged)][1]) {
                $merged[array_key_last($merged)][1] = max($merged[array_key_last($merged)][1], (float) $to);
            } else {
                $merged[] = [(float) $from, (float) $to];
            }
        }

        return $merged;
    }

    /**
     * Get the parts of an interval outside the given (merged) intervals.
     *
     * @param  array{0: float, 1: float}  $interval
     * @param  list<array{0: float, 1: float}>  $holes
     * @return list<array{0: float, 1: float}>
     */
    protected static function subtract(array $interval, array $holes): array
    {
        [$from, $to] = $interval;
        $parts = [];
        foreach ($holes as [$a, $b]) {
            if ($b <= $from || $a >= $to) {
                continue;
            }
            if ($a > $from) {
                $parts[] = [$from, $a];
            }
            $from = max($from, $b);
        }
        if ($to > $from) {
            $parts[] = [$from, $to];
        }

        return $parts;
    }
}
