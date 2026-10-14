<?php

namespace Ogunsakin01\VideoDemo\Library;

use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Devices;
use RuntimeException;

/** Copies a finished video to the library disk and lists it in the manifest */
class Publisher
{
    public function __construct(protected Manifest $manifest) {}

    public function publish(Video $video): void
    {
        $work = Storage::disk(config('video-demo.storage.work_disk'));
        $devices = array_values(array_filter($video->recordedDevices(), fn ($d) => $video->hasRecording($d)));

        if ($devices === []) {
            throw new RuntimeException('This video has no recording to publish yet.');
        }

        $disk = $this->manifest->disk();
        $old = collect($this->manifest->all())->firstWhere('slug', $video->slug);

        // A new file name for each version, so browsers and CDNs never show an old cut
        $sources = [];
        foreach ($devices as $device) {
            $base = $this->manifest->path("{$video->slug}-v{$video->version}-$device");
            $disk->writeStream("$base.mp4", $work->readStream($video->workFile($device)));
            $disk->put("$base.jpg", $work->get($video->workFile($device, 'poster')));
            $screen = Devices::get($device);
            $sources[$device] = [
                'video' => "$base.mp4",
                'poster' => "$base.jpg",
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
            'sources' => $sources,
            'version' => $video->version,
            'published_at' => now()->toIso8601String(),
        ]);

        if ($old) {
            $kept = collect($sources)->flatMap(fn ($s) => [$s['video'], $s['poster']])->all();
            $disk->delete(array_diff(Manifest::files($old), $kept));
        }

        $video->update(['published_at' => now()]);
    }

    public function unpublish(Video $video): void
    {
        if ($removed = $this->manifest->remove($video->slug)) {
            $this->manifest->disk()->delete(Manifest::files($removed));
        }

        $video->update(['published_at' => null]);
    }
}
