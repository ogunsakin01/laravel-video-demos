<?php

namespace Ogunsakin01\VideoDemo\Library;

use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Devices;
use RuntimeException;

/**
 * Copies a finished video to the library disk and lists it in the manifest.
 */
class Publisher
{
    /**
     * Create a new publisher instance.
     */
    public function __construct(protected Manifest $manifest) {}

    /**
     * Publish a video's recordings to the library.
     *
     * Files from the previous version that the new one doesn't use are deleted.
     */
    public function publish(Video $video): void
    {
        $work = Storage::disk(config('video-demo.storage.work_disk'));
        $devices = array_values(array_filter($video->recordedDevices(), fn ($d) => $video->hasRecording($d)));

        if ($devices === []) {
            throw new RuntimeException('This video has no recording to publish yet.');
        }

        $disk = $this->manifest->disk();
        $old = collect($this->manifest->all())->firstWhere('slug', $video->slug);

        // A new file name for each version, so browsers and CDNs never show an old cut.
        $sources = [];
        foreach ($devices as $device) {
            $base = $this->manifest->path("{$video->slug}-v{$video->version}-$device");
            $disk->writeStream("$base.mp4", $work->readStream($video->workFile($device)));
            $disk->put("$base.jpg", $work->get($video->workFile($device, 'poster')));
            // The spoken lines, when the video has a voice-over.
            $subtitles = $work->exists($video->workFile($device, 'subtitles')) ? "$base.vtt" : null;
            if ($subtitles) {
                $disk->put($subtitles, $work->get($video->workFile($device, 'subtitles')));
            }
            $screen = Devices::get($device);
            $sources[$device] = [
                'video' => "$base.mp4",
                'poster' => "$base.jpg",
                'subtitles' => $subtitles,
                'width' => $screen['width'],
                'height' => $screen['height'],
                'duration' => $video->recordings[$device]['seconds'] ?? $video->duration_seconds,
                'chapters' => $video->recordings[$device]['chapters'] ?? [],
            ];
        }

        $primary = $sources[Devices::primary($devices)];
        $this->manifest->put([
            'slug' => $video->slug,
            'title' => $video->title,
            'summary' => $video->plan['summary'] ?? '',
            'visibility' => $video->visibility,
            'duration' => $primary['duration'],
            'chapters' => $primary['chapters'],
            'video' => $primary['video'],
            'poster' => $primary['poster'],
            'subtitles' => $primary['subtitles'],
            'sources' => $sources,
            'version' => $video->version,
            'published_at' => now()->toIso8601String(),
        ]);

        if ($old) {
            $kept = collect($sources)->flatMap(fn ($s) => array_filter([$s['video'], $s['poster'], $s['subtitles']]))->all();
            $disk->delete(array_diff(Manifest::files($old), $kept));
        }

        $video->update(['published_at' => now()]);
    }

    /**
     * Remove a video and its files from the library.
     */
    public function unpublish(Video $video): void
    {
        if ($removed = $this->manifest->remove($video->slug)) {
            $this->manifest->disk()->delete(Manifest::files($removed));
        }

        $video->update(['published_at' => null]);
    }
}
