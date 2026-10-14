<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;

/**
 * Converts the browser's WebM recording to an MP4 that plays everywhere, and grabs a poster frame.
 */
class Encoder
{
    /**
     * Encode a WebM recording to MP4 and save a poster frame.
     *
     * $trimSeconds (in recording time) cuts the browser's start-up from the
     * beginning; $speed plays the recording back that many times faster.
     */
    public function encode(string $webm, string $mp4, string $poster, float $trimSeconds = 0, float $speed = 1): void
    {
        $ffmpeg = config('video-demo.recorder.ffmpeg', 'ffmpeg');
        $speed = max($speed, 0.1);

        $this->run([$ffmpeg, '-y', '-v', 'error',
            // Squeezes the timestamps, so frames play $speed times faster.
            '-itsscale', number_format(1 / $speed, 6, '.', ''),
            '-i', $webm, '-ss', number_format($trimSeconds / $speed, 2, '.', ''), '-r', '30',
            '-c:v', 'libx264', '-crf', '26', '-preset', 'slow', '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart', '-an', $mp4]);

        $this->run([$ffmpeg, '-y', '-v', 'error', '-ss', '2', '-i', $mp4, '-frames:v', '1', '-q:v', '4', $poster]);
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
