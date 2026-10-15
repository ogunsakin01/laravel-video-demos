<?php

namespace Ogunsakin01\VideoDemo\Voice;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use RuntimeException;

/**
 * Turns a video's voice-over lines into audio clips, one per line, in the
 * chosen voice. Each clip is saved by voice and text, so a line is only
 * ever spoken once: recording a plan again costs nothing. Clips are kept as
 * 16-bit stereo WAV at 44.1 kHz, ready to mix.
 */
class Speaker
{
    /**
     * Create a new speaker instance.
     */
    public function __construct(protected Encoder $encoder) {}

    /**
     * Speak each line, returning its clip and length, keyed by the line.
     *
     * With $mixable false the clips are kept as the voice made them, without
     * ffmpeg: browsers play them as they are, which suits a sample.
     *
     * @param  list<string>  $lines
     * @return array<string, array{file: string, seconds: float}>
     *
     * @throws RuntimeException When the voice can't be used or fails.
     */
    public function speak(string $voice, array $lines, bool $mixable = true): array
    {
        [$provider, $id] = Voices::parse($voice);
        $dir = Recorder::workDir(trim((string) config('video-demo.storage.work_path'), '/').'/voice');
        File::ensureDirectoryExists($dir);

        $clips = [];
        $missing = [];
        foreach (array_unique(array_filter(array_map('trim', $lines))) as $text) {
            $file = "$dir/".($mixable ? '' : 'raw-').sha1("$voice\n$text").'.wav';
            $clips[$text] = $file;
            if (! is_file($file)) {
                $missing[$text] = $file;
            }
        }

        if ($missing !== []) {
            $raw = "$dir/raw-".uniqid();
            File::ensureDirectoryExists($raw);
            try {
                $spoken = match ($provider) {
                    'kokoro' => $this->kokoro($id, $missing, $raw),
                    'system' => $this->system($id, $missing, $raw),
                    default => throw new RuntimeException("There is no voice [$voice]."),
                };
                foreach ($spoken as $text => $file) {
                    $mixable ? $this->encoder->toWav($file, $missing[$text]) : File::move($file, $missing[$text]);
                }
            } finally {
                File::deleteDirectory($raw);
            }
        }

        return array_map(fn ($file) => ['file' => $file, 'seconds' => Wav::seconds($file)], $clips);
    }

    /**
     * Speak the lines with the local voice, loading its model once.
     *
     * @param  array<string, string>  $lines
     * @return array<string, string>
     */
    protected function kokoro(string $id, array $lines, string $dir): array
    {
        if (! Voices::localInstalled()) {
            throw new RuntimeException('The local voice is not installed. Run php artisan video-demo:install-voice.');
        }

        $out = [];
        $job = ['voice' => $id, 'cacheDir' => Voices::modelCache(), 'lines' => []];
        foreach (array_keys($lines) as $i => $text) {
            $out[$text] = "$dir/$i.wav";
            $job['lines'][] = ['text' => $text, 'out' => $out[$text]];
        }
        File::put("$dir/job.json", json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $result = Process::timeout(900)
            ->env(['NODE_PATH' => (string) config('video-demo.recorder.node_modules')])
            ->run([config('video-demo.recorder.node', 'node'), Recorder::script('voice.cjs'), "$dir/job.json"]);
        $status = is_file("$dir/result.json") ? json_decode((string) File::get("$dir/result.json"), true) : null;

        if (! ($status['ok'] ?? false)) {
            throw new RuntimeException('The local voice failed: '.($status['error'] ?? (trim($result->errorOutput()) ?: 'no result')));
        }

        return $out;
    }

    /**
     * Speak the lines with the computer's own voice.
     *
     * @param  array<string, string>  $lines
     * @return array<string, string>
     */
    protected function system(string $id, array $lines, string $dir): array
    {
        $out = [];
        foreach (array_keys($lines) as $i => $text) {
            $file = "$dir/$i.wav";
            $command = match (PHP_OS_FAMILY) {
                'Darwin' => ['say', '-v', $id, '-o', $file, '--file-format=WAVE', '--data-format=LEI16@24000', $text],
                'Windows' => ['powershell', '-NoProfile', '-Command', 'Add-Type -AssemblyName System.Speech; $s = New-Object System.Speech.Synthesis.SpeechSynthesizer; '
                    .'$s.SelectVoice('.$this->quote($id).'); $s.SetOutputToWaveFile('.$this->quote($file).'); $s.Speak('.$this->quote($text).'); $s.Dispose()'],
                default => ['espeak-ng', '-v', $id, '-w', $file, $text],
            };

            $result = Process::timeout(120)->run($command);
            if ($result->failed() || ! is_file($file)) {
                throw new RuntimeException("This computer's voice failed: ".(trim($result->errorOutput()) ?: 'no sound was made'));
            }
            $out[$text] = $file;
        }

        return $out;
    }

    /**
     * Quote a value for a PowerShell command.
     */
    protected function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
