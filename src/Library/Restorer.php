<?php

namespace Ogunsakin01\VideoDemo\Library;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Throwable;

/**
 * Brings published videos back into the studio when its database lost them,
 * such as after the database was reset, or on a new machine sharing the
 * library disk. Each video is rebuilt from the record saved next to it when
 * it was published (its prompt, plan, style and recordings), or, for videos
 * published before records were saved, from what the library lists. Its
 * files are copied back to the work disk, so it plays, can be published
 * again and recorded again.
 */
class Restorer
{
    /**
     * The fields of a video saved with it when it is published: what is
     * needed to manage it and to record it again.
     */
    public const FIELDS = [
        'slug', 'title', 'prompt', 'start_path', 'target_seconds', 'model', 'effort', 'visibility',
        'plan', 'style', 'devices', 'recordings', 'duration_seconds', 'version', 'max_cost', 'speed',
        'max_steps', 'record_as', 'record_as_label', 'sample_data', 'sample_records', 'voice', 'music',
        'music_volume', 'language', 'page_language', 'source_files', 'plan_edited_at', 'cost',
        'input_tokens', 'cache_write_tokens', 'cache_read_tokens', 'output_tokens',
    ];

    /**
     * Create a new restorer instance.
     */
    public function __construct(protected Manifest $manifest) {}

    /**
     * Get the published videos the studio has no video for.
     *
     * @return list<array<string, mixed>>
     */
    public function missing(): array
    {
        $all = $this->manifest->all();
        $known = Video::query()->whereIn('slug', array_column($all, 'slug'))->pluck('slug')->all();

        return array_values(array_filter($all, fn ($v) => ! in_array($v['slug'], $known, true)));
    }

    /**
     * Bring back every published video the studio has no video for.
     *
     * @return list<Video>
     */
    public function restore(): array
    {
        $restored = [];
        foreach ($this->missing() as $entry) {
            try {
                $restored[] = [$this->restoreOne($entry), $entry['translation_of'] ?? null];
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Versions in other languages are linked to their original once both are back.
        foreach ($restored as [$video, $of]) {
            if ($of && ($source = Video::query()->where('slug', $of)->value('id'))) {
                $video->update(['source_video_id' => $source]);
            }
        }

        return array_column($restored, 0);
    }

    /**
     * Rebuild one video from its library entry and copy its files back.
     */
    protected function restoreOne(array $entry): Video
    {
        $disk = $this->manifest->disk();
        $record = ! empty($entry['record']) && $disk->exists($entry['record'])
            ? (array) json_decode((string) $disk->get($entry['record']), true)
            : [];
        $sources = Manifest::sources($entry);

        $video = Video::create([
            ...$this->fromEntry($entry, $sources),
            // Fields left empty keep their defaults.
            ...array_filter(array_intersect_key($record, array_flip(self::FIELDS)), fn ($value) => $value !== null),
            'slug' => $entry['slug'],
            'status' => VideoStatus::Ready,
            'published_at' => isset($entry['published_at']) ? Carbon::parse($entry['published_at']) : now(),
        ]);

        $work = Storage::disk(config('video-demo.storage.work_disk'));
        foreach ($sources as $device => $files) {
            foreach (['video', 'poster', 'subtitles'] as $kind) {
                if (! empty($files[$kind]) && $disk->exists($files[$kind])) {
                    $work->writeStream($video->workFile($device, $kind), $disk->readStream($files[$kind]));
                }
            }
        }
        $video->addLog('Brought back from the library: the studio\'s database no longer had it'.($record === [] ? '. It was published before its plan was saved with it, so plan it again to record it again.' : '.'));

        return $video;
    }

    /**
     * Get what the library lists about a video, as a video's fields: enough
     * to show, publish and plan it again when no record was saved with it.
     *
     * @param  array<string, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    protected function fromEntry(array $entry, array $sources): array
    {
        $title = (string) ($entry['title'] ?? $entry['slug']);
        $duration = (float) ($entry['duration'] ?? 0);

        return [
            'title' => $title,
            'prompt' => trim((string) ($entry['summary'] ?? '')) ?: $title,
            'target_seconds' => max(15, (int) round($duration)),
            'model' => Pricing::defaultModel(),
            'effort' => (string) config('video-demo.ai.effort', 'high'),
            'visibility' => $entry['visibility'] ?? 'public',
            'language' => $entry['language'] ?? null,
            'version' => (int) ($entry['version'] ?? 1),
            'duration_seconds' => $duration,
            'devices' => array_keys($sources),
            'recordings' => array_map(fn ($s) => ['seconds' => (float) ($s['duration'] ?? $duration), 'chapters' => $s['chapters'] ?? []], $sources),
            'plan' => ['title' => $title, 'summary' => (string) ($entry['summary'] ?? ''), 'steps' => []],
        ];
    }
}
