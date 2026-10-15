<?php

namespace Ogunsakin01\VideoDemo\Voice;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use RuntimeException;

/**
 * Builds a recorded version's sound track: its voice-over clips, each at the
 * moment its step started, over the background music, mixed by
 * resources/recorder/mix.cjs. Also writes the spoken lines as subtitles.
 */
class Soundtrack
{
    /**
     * Seconds after a step starts before its line begins.
     */
    public const LEAD = 0.15;

    /**
     * Create a new soundtrack instance.
     */
    public function __construct(protected Encoder $encoder) {}

    /**
     * Mix the clips and music into a WAV file of the given length.
     *
     * @param  list<array{file: string, at: float}>  $clips  When each clip starts, in seconds of the finished video.
     * @param  ?array{file: string, volume: float}  $music
     *
     * @throws RuntimeException When mixing fails.
     */
    public function mix(array $clips, ?array $music, float $seconds, string $wav): void
    {
        $dir = Recorder::workDir(trim((string) config('video-demo.storage.work_path'), '/').'/mix/'.uniqid());
        File::ensureDirectoryExists($dir);

        try {
            // Music in any format is converted once to what the mixer reads.
            if ($music) {
                $converted = "$dir/music.wav";
                $this->encoder->toWav($music['file'], $converted);
                $music = ['file' => $converted, 'volume' => max(0.0, min(1.0, $music['volume']))];
            }

            File::put("$dir/job.json", json_encode(['seconds' => $seconds, 'out' => $wav, 'clips' => $clips, 'music' => $music], JSON_UNESCAPED_SLASHES));
            $result = Process::timeout(300)->run([config('video-demo.recorder.node', 'node'), Recorder::script('mix.cjs'), "$dir/job.json"]);
            $status = is_file("$dir/result.json") ? json_decode((string) File::get("$dir/result.json"), true) : null;

            if (! ($status['ok'] ?? false)) {
                throw new RuntimeException('The sound track could not be mixed: '.($status['error'] ?? (trim($result->errorOutput()) ?: 'no result')));
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Write the spoken lines as WebVTT subtitles.
     *
     * @param  list<array{text: string, at: float, seconds: float}>  $lines
     */
    public function subtitles(array $lines, string $vtt): void
    {
        $cues = ['WEBVTT', ''];
        foreach ($lines as $i => $line) {
            $cues[] = (string) ($i + 1);
            $cues[] = static::time($line['at']).' --> '.static::time($line['at'] + $line['seconds']);
            $cues[] = $line['text'];
            $cues[] = '';
        }

        File::ensureDirectoryExists(dirname($vtt));
        File::put($vtt, implode("\n", $cues));
    }

    /**
     * Format seconds as a WebVTT time, such as 00:01:02.500.
     */
    protected static function time(float $seconds): string
    {
        $ms = (int) round(max(0, $seconds) * 1000);

        return sprintf('%02d:%02d:%02d.%03d', intdiv($ms, 3_600_000), intdiv($ms, 60_000) % 60, intdiv($ms, 1000) % 60, $ms % 1000);
    }
}
