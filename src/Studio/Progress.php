<?php

namespace Ogunsakin01\VideoDemo\Studio;

use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\CostEstimator;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;

/**
 * Estimates how far along a video being made is, and how long it has left,
 * from the stage it is in, how long it has been there, and how long each
 * stage usually takes for a video of its length and versions.
 */
class Progress
{
    /**
     * Each stage's share of the bar: where it starts and ends, in percent.
     */
    protected const STAGES = [
        'checking' => [0, 8],
        'planning' => [8, 45],
        'recording' => [45, 85],
        'encoding' => [85, 99],
    ];

    /**
     * Get the video's progress, or null when it isn't being made. The stage is
     * only given while the video waits for a worker; otherwise the status says it.
     *
     * @return ?array{percent: int, remaining: int, stage: ?string}
     */
    public static function for(Video $video): ?array
    {
        if (! $video->status->isWorking()) {
            return null;
        }

        $expected = static::expected($video);
        $stage = $video->status === VideoStatus::Queued ? null : $video->status->value;
        $since = $video->status_at ?? $video->updated_at;
        $elapsed = $since ? max(0, (int) $since->diffInSeconds(now())) : 0;

        if ($stage === null || ! isset(self::STAGES[$stage])) {
            return ['percent' => 0, 'remaining' => (int) array_sum($expected), 'stage' => 'Waiting for a queue worker'];
        }

        // Within a stage, time moves the bar on, but never past the stage's end.
        [$from, $to] = self::STAGES[$stage];
        $now = $expected[$stage] ?? 30;
        $share = min(0.95, $elapsed / max(1, $now));
        $percent = (int) round($from + ($to - $from) * $share);

        // What is left of this stage, and every stage after it.
        $later = array_slice(array_keys(self::STAGES), array_search($stage, array_keys(self::STAGES), true) + 1);
        $remaining = max(5, $now - $elapsed) + (int) array_sum(array_intersect_key($expected, array_flip($later)));

        return ['percent' => $percent, 'remaining' => $remaining, 'stage' => null];
    }

    /**
     * Get how long each stage usually takes for this video, in seconds.
     * Planning is left out when the same plan is being recorded again.
     *
     * @return array<string, int>
     */
    protected static function expected(Video $video): array
    {
        $devices = max(1, count($video->deviceList()));
        $replanning = ! str_contains((string) collect($video->log ?? [])->pluck('message')->reverse()->first(fn ($m) => str_contains($m, 'Queued') || str_contains($m, 'Trying again')), 'same plan');
        $turns = app(CostEstimator::class)->estimate($video->model, $video->effort, $video->target_seconds, app(CodeTools::class)->routeCount())['turns'];

        return array_filter([
            'checking' => $video->sample_data ? 30 : 15,
            'planning' => $replanning || empty($video->plan['steps']) ? $turns * 8 : 0,
            'recording' => $devices * (int) ($video->target_seconds * $video->playbackSpeed() + 20),
            'encoding' => $devices * (int) ((8 + $video->target_seconds * 0.15) * (! empty($video->style['zoom']) ? 4 : 1)),
        ]);
    }
}
