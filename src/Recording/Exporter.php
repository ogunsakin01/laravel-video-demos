<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Ogunsakin01\VideoDemo\Models\Video;
use RuntimeException;

/**
 * Makes copies of a finished video for places that don't take its MP4 as
 * it is: an animated GIF for READMEs, changelogs and emails, and square
 * and vertical (9:16) MP4s for social posts, with the title above the app.
 * Made from the recording on this machine, at no AI cost.
 */
class Exporter
{
    /**
     * The formats, with their file type and size.
     */
    public const FORMATS = [
        'gif' => ['label' => 'GIF', 'ext' => 'gif', 'mime' => 'image/gif'],
        'square' => ['label' => 'Square', 'ext' => 'mp4', 'mime' => 'video/mp4', 'width' => 1080, 'height' => 1080],
        'vertical' => ['label' => 'Vertical (9:16)', 'ext' => 'mp4', 'mime' => 'video/mp4', 'width' => 1080, 'height' => 1920],
    ];

    /**
     * Frames a second in square and vertical copies.
     */
    protected const FRAME_FPS = 30;

    /**
     * Create a new exporter instance.
     */
    public function __construct(protected Recorder $recorder, protected Encoder $encoder) {}

    /**
     * Make a copy of a video in a format, from one of its versions (the
     * primary one when left out), and get its path on the work disk.
     *
     * @throws InvalidArgumentException For an unknown format or a version that wasn't recorded.
     * @throws RuntimeException When it can't be made.
     */
    public function export(Video $video, string $format, ?string $device = null): string
    {
        if (! isset(self::FORMATS[$format])) {
            throw new InvalidArgumentException("Unknown format [$format]. Choose ".implode(', ', array_keys(self::FORMATS)).'.');
        }
        $device ??= $video->primaryDevice();
        if (! $video->hasRecording($device)) {
            throw new InvalidArgumentException('This video has no '.(Devices::all()[$device]['label'] ?? $device).' recording to make a copy from.');
        }

        $work = Storage::disk(config('video-demo.storage.work_disk'));
        $source = $work->path($video->workFile($device));
        $file = static::file($video, $format);
        $dir = Recorder::workDir($video->workPath("exports/work-$format"));
        File::deleteDirectory($dir);

        try {
            if ($format === 'gif') {
                // Long videos get fewer frames, to keep the file small enough to share.
                $seconds = (float) ($video->recordings[$device]['seconds'] ?? $video->duration_seconds ?? 30);
                $this->encoder->frames($source, "$dir/in", $seconds > 45 ? 8 : 12);
                $this->recorder->makeGif("$dir/in", "$dir/out/video.gif", $seconds > 45 ? 8 : 12, $seconds > 45 ? 640 : 800);
                $this->keep("$dir/out/video.gif", $work->path($file));
            } else {
                $screen = Devices::get($device);
                $this->encoder->frames($source, "$dir/in", self::FRAME_FPS);
                $this->recorder->reframe("$dir/in", "$dir/out", [
                    'width' => self::FORMATS[$format]['width'],
                    'height' => self::FORMATS[$format]['height'],
                    'srcWidth' => $screen['width'],
                    'srcHeight' => $screen['height'],
                    'fps' => self::FRAME_FPS,
                    'eyebrow' => 'HOW-TO · '.gmdate('i:s', (int) round((float) ($video->recordings[$device]['seconds'] ?? $video->duration_seconds ?? 0))),
                    'title' => $video->title ?: ($video->plan['title'] ?? 'How-to video'),
                    'footer' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
                    'theme' => $video->style['theme'] ?? 'light',
                    'accent' => $video->style['accent'] ?? '#171717',
                    // A video that zooms in on each step already keeps it in the middle.
                    'follow' => empty($video->style['zoom']),
                    'focus' => $video->recordings[$device]['focus'] ?? [],
                ]);
                $this->encoder->join("$dir/out", "$dir/video.mp4", self::FRAME_FPS);
                $this->encoder->copyAudio($source, "$dir/video.mp4");
                $this->keep("$dir/video.mp4", $work->path($file));
            }
        } finally {
            File::deleteDirectory($dir);
        }

        return $file;
    }

    /**
     * Move a finished copy to where it is kept.
     */
    protected function keep(string $from, string $to): void
    {
        File::ensureDirectoryExists(dirname($to));
        File::move($from, $to);
    }

    /**
     * Get the path on the work disk of a video's copy in a format.
     */
    public static function file(Video $video, string $format): string
    {
        return $video->workPath("exports/$format.".(self::FORMATS[$format]['ext'] ?? 'bin'));
    }

    /**
     * Determine if a video's copy in a format is made from its latest
     * recording and is still on the disk.
     */
    public static function isCurrent(Video $video, string $format): bool
    {
        $export = $video->exports[$format] ?? null;

        return ($export['status'] ?? null) === 'ready'
            && ($export['version'] ?? null) === $video->version
            && Storage::disk(config('video-demo.storage.work_disk'))->exists(static::file($video, $format));
    }
}
