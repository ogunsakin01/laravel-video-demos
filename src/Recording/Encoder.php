<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;

/**
 * Converts the browser's WebM recording to an MP4 that plays everywhere,
 * grabs a poster frame, and adds a video's sound track.
 */
class Encoder
{
    /**
     * Frames per second of finished videos.
     */
    public const FPS = 30;

    /**
     * Encode a WebM recording to MP4 and save a poster frame.
     *
     * $trimSeconds (in recording time) cuts the browser's start-up from the
     * beginning; $speed plays the recording back that many times faster.
     */
    public function encode(string $webm, string $mp4, string $poster, float $trimSeconds = 0, float $speed = 1, ?Timeline $timeline = null): void
    {
        $ffmpeg = config('video-demo.recorder.ffmpeg', 'ffmpeg');
        $speed = max($speed, 0.1);

        if ($timeline?->hasCuts()) {
            $this->encodeCut($webm, $mp4, $trimSeconds, $timeline);
        } else {
            $this->run([$ffmpeg, '-y', '-v', 'error',
                // Squeezes the timestamps, so frames play $speed times faster.
                '-itsscale', number_format(1 / $speed, 6, '.', ''),
                '-i', $webm, '-ss', number_format($trimSeconds / $speed, 2, '.', ''),
                // Ends where the recording did, before the browser closed.
                ...($timeline ? ['-t', number_format($timeline->seconds(), 2, '.', '')] : []),
                '-r', (string) self::FPS,
                '-c:v', 'libx264', '-crf', '26', '-preset', 'slow', '-pix_fmt', 'yuv420p',
                '-movflags', '+faststart', '-an', $mp4]);
        }

        $this->poster($mp4, $poster);
    }

    /**
     * Encode only the parts the timeline keeps: each part is cut out at its
     * own pace, the parts are joined, and the whole is played at the timeline's
     * speed. Uses no filters, so any ffmpeg build works.
     */
    protected function encodeCut(string $webm, string $mp4, float $trimSeconds, Timeline $timeline): void
    {
        $ffmpeg = config('video-demo.recorder.ffmpeg', 'ffmpeg');
        $dir = dirname($mp4).'/parts-'.pathinfo($mp4, PATHINFO_FILENAME);
        File::ensureDirectoryExists($dir);
        $list = [];

        try {
            foreach ($timeline->keep as $i => [$from, $to]) {
                $part = sprintf('%s/%03d.mp4', $dir, $i);
                $this->run([$ffmpeg, '-y', '-v', 'error',
                    '-ss', number_format($trimSeconds + $from / 1000, 3, '.', ''), '-i', $webm,
                    '-t', number_format(($to - $from) / 1000, 3, '.', ''), '-r', (string) self::FPS,
                    '-c:v', 'libx264', '-crf', '16', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-an', $part]);
                $list[] = "file '".str_replace("'", "'\\''", $part)."'";
            }
            File::put("$dir/parts.txt", implode("\n", $list)."\n");

            $this->run([$ffmpeg, '-y', '-v', 'error',
                '-itsscale', number_format(1 / $timeline->speed, 6, '.', ''),
                '-f', 'concat', '-safe', '0', '-i', "$dir/parts.txt", '-r', (string) self::FPS,
                '-c:v', 'libx264', '-crf', '26', '-preset', 'slow', '-pix_fmt', 'yuv420p',
                '-movflags', '+faststart', '-an', $mp4]);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Get a media file's length in seconds, from its header, or null when
     * the file doesn't say.
     */
    public function duration(string $file): ?float
    {
        if (! is_file($file)) {
            return null;
        }
        $result = Process::run([config('video-demo.recorder.ffmpeg', 'ffmpeg'), '-hide_banner', '-i', $file]);
        if (! preg_match('/Duration: (\d+):(\d+):(\d+(?:\.\d+)?)/', $result->errorOutput().$result->output(), $m)) {
            return null;
        }

        return $m[1] * 3600 + $m[2] * 60 + (float) $m[3];
    }

    /**
     * Save a poster frame from an MP4.
     */
    public function poster(string $mp4, string $poster): void
    {
        $this->run([config('video-demo.recorder.ffmpeg', 'ffmpeg'), '-y', '-v', 'error', '-ss', '2', '-i', $mp4, '-frames:v', '1', '-q:v', '4', $poster]);
    }

    /**
     * Split an MP4 into numbered JPEG frames in $dir.
     */
    public function frames(string $mp4, string $dir): void
    {
        File::ensureDirectoryExists($dir);
        $this->run([config('video-demo.recorder.ffmpeg', 'ffmpeg'), '-y', '-v', 'error', '-i', $mp4, '-r', (string) self::FPS, '-q:v', '2', "$dir/%05d.jpg"]);
    }

    /**
     * Join numbered JPEG frames from $dir into an MP4.
     */
    public function join(string $dir, string $mp4): void
    {
        $this->run([config('video-demo.recorder.ffmpeg', 'ffmpeg'), '-y', '-v', 'error', '-framerate', (string) self::FPS, '-i', "$dir/%05d.jpg",
            '-c:v', 'libx264', '-crf', '26', '-preset', 'slow', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-an', $mp4]);
    }

    /**
     * Convert any audio file to 16-bit stereo WAV at 44.1 kHz, the format
     * voice clips and music are mixed in.
     */
    public function toWav(string $input, string $wav): void
    {
        File::ensureDirectoryExists(dirname($wav));
        $this->run([config('video-demo.recorder.ffmpeg', 'ffmpeg'), '-y', '-v', 'error', '-i', $input, '-vn', '-ar', '44100', '-ac', '2', '-c:a', 'pcm_s16le', $wav]);
    }

    /**
     * Add a sound track to an MP4, in place. The picture is copied as it is.
     */
    public function addAudio(string $mp4, string $wav): void
    {
        $out = dirname($mp4).'/with-sound-'.basename($mp4);
        $this->run([config('video-demo.recorder.ffmpeg', 'ffmpeg'), '-y', '-v', 'error', '-i', $mp4, '-i', $wav,
            '-map', '0:v', '-map', '1:a', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $out]);
        File::move($out, $mp4);
    }

    /**
     * Run an ffmpeg command, turning a missing binary or a crash into a readable error.
     */
    protected function run(array $command): void
    {
        try {
            $result = Process::timeout(600)->run($command);
        } catch (ProcessStartFailedException) {
            throw new RuntimeException('ffmpeg was not found. Install it, or set VIDEO_DEMO_FFMPEG to its path.');
        } catch (ProcessSignaledException $e) {
            throw new RuntimeException("ffmpeg crashed (signal {$e->getSignal()}). Check that it runs on its own: ffmpeg -version");
        }

        if ($result->failed()) {
            $error = trim($result->errorOutput()) ?: 'exit code '.$result->exitCode();

            throw new RuntimeException(str_contains($error, 'not found') || $result->exitCode() === 127
                ? 'ffmpeg was not found. Install it, or set VIDEO_DEMO_FFMPEG to its path.'
                : "ffmpeg failed: $error");
        }
    }
}
