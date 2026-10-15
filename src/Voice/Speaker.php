<?php

namespace Ogunsakin01\VideoDemo\Voice;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use RuntimeException;

/**
 * Turns a video's voice-over lines into audio clips, one per line, in the
 * chosen voice: local, the computer's own, or an AI provider's. Each clip is saved by voice and text, so a line is only
 * ever spoken once: recording a plan again costs nothing. Clips are kept as
 * 16-bit stereo WAV at 44.1 kHz, ready to mix.
 */
class Speaker
{
    /**
     * What the last call's new lines cost, in US dollars: 0 for local voices,
     * and for lines that were already saved.
     */
    public float $cost = 0.0;

    /**
     * Create a new speaker instance.
     */
    public function __construct(protected Encoder $encoder) {}

    /**
     * Get the tone a voice speaks in for a style: calm, warm or upbeat, by its pace.
     */
    public static function tone(array $style): string
    {
        return match ($style['pace'] ?? 'normal') {
            'relaxed' => 'calm, unhurried and reassuring',
            'brisk' => 'upbeat and energetic, but clear',
            default => 'warm, clear and friendly',
        };
    }

    /**
     * Speak each line, returning its clip and length, keyed by the line.
     *
     * With $mixable false the clips are kept as the voice made them, without
     * ffmpeg: browsers play them as they are, which suits a sample.
     *
     * $tone is how the line is spoken, for voices that take one (OpenAI and
     * Gemini), such as "calm and unhurried".
     *
     * @param  list<string>  $lines
     * @return array<string, array{file: string, seconds: float}>
     *
     * @throws RuntimeException When the voice can't be used or fails.
     */
    public function speak(string $voice, array $lines, bool $mixable = true, string $tone = 'warm, clear and friendly'): array
    {
        [$provider, $id] = Voices::parse($voice);
        $this->cost = 0.0;
        // ElevenLabs sends MP3; a sample is kept as it comes, for the browser to play.
        $extension = ! $mixable && $provider === 'elevenlabs' ? 'mp3' : 'wav';
        $dir = Recorder::workDir(trim((string) config('video-demo.storage.work_path'), '/').'/voice');
        File::ensureDirectoryExists($dir);

        $clips = [];
        $missing = [];
        $order = array_values(array_unique(array_filter(array_map('trim', $lines))));
        foreach ($order as $i => $text) {
            // Cloud voices hear the lines around each one, so those are part of what makes a clip.
            $around = in_array($provider, ['openai', 'elevenlabs'], true) ? "\n".($order[$i - 1] ?? '')."\n".($order[$i + 1] ?? '') : '';
            $file = "$dir/".($mixable ? '' : 'raw-').sha1("$voice\n$tone\n$text$around").".$extension";
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
                    'openai', 'gemini', 'elevenlabs' => $this->cloud($provider, $id, $missing, $raw, $tone, $order),
                    default => throw new RuntimeException("There is no voice [$voice]."),
                };
                foreach ($spoken as $text => $file) {
                    $mixable ? $this->encoder->toWav($file, $missing[$text]) : File::move($file, $missing[$text]);
                }
            } finally {
                File::deleteDirectory($raw);
            }
        }

        return array_map(fn ($file) => ['file' => $file, 'seconds' => str_ends_with($file, '.wav') ? Wav::seconds($file) : 0.0], $clips);
    }

    /**
     * Speak the lines with an AI provider's voice, one request a line, adding
     * what each costs. Each request hears the lines before and after it where
     * the provider allows, so the voice-over flows as one, rather than every
     * line starting afresh like an announcement.
     *
     * @param  array<string, string>  $lines
     * @param  list<string>  $order  Every line of the voice-over, in order.
     * @return array<string, string>
     */
    protected function cloud(string $provider, string $id, array $lines, string $dir, string $tone, array $order = []): array
    {
        $manner = 'like a person talking a colleague through their screen: relaxed and natural, with small pauses, not an announcer or an advert';
        $key = Voices::providerKey($provider) ?? throw new RuntimeException("Set the $provider API key to use its voices.");
        $model = (string) config("video-demo.voice.providers.$provider.model");

        $out = [];
        foreach (array_keys($lines) as $i => $text) {
            $at = array_search($text, $order, true);
            $before = $at === false ? '' : ($order[$at - 1] ?? '');
            $after = $at === false ? '' : ($order[$at + 1] ?? '');
            $response = match ($provider) {
                'openai' => Http::withToken($key)->timeout(120)->post('https://api.openai.com/v1/audio/speech', [
                    'model' => $model, 'input' => $text, 'voice' => $id, 'response_format' => 'wav',
                    'instructions' => "Speak as the narrator of a short how-to video, $manner. Tone: $tone."
                        .($before !== '' ? " This line follows straight on from: \"$before\"" : ' This is the first line.'),
                ]),
                'gemini' => Http::withHeaders(['x-goog-api-key' => $key])->timeout(120)->post('https://generativelanguage.googleapis.com/v1beta/interactions', [
                    'model' => $model,
                    'input' => [['type' => 'user_input', 'content' => [['type' => 'text', 'text' => $text, 'annotations' => [['type' => 'speech_metadata', 'style' => "$tone, $manner"]]]]]],
                    'response_format' => ['type' => 'audio'],
                    'generation_config' => ['speech_config' => [['voice' => $id]]],
                ]),
                default => Http::withHeaders(['xi-api-key' => $key])->accept('audio/mpeg')->timeout(120)
                    ->post("https://api.elevenlabs.io/v1/text-to-speech/$id?output_format=mp3_44100_128", array_filter([
                        'text' => $text, 'model_id' => $model,
                        // ElevenLabs carries the intonation across from the lines around it.
                        'previous_text' => $before, 'next_text' => $after,
                    ], fn ($v) => $v !== '')),
            };

            if ($response->failed()) {
                $said = $response->json('error.message') ?? $response->json('detail.message') ?? $response->json('detail') ?? $response->body();
                throw new RuntimeException(ucfirst($provider)." couldn't speak a line (HTTP {$response->status()}): ".Str::limit(is_string($said) ? $said : json_encode($said), 300));
            }

            $audio = $provider === 'gemini' ? $this->geminiAudio((array) $response->json()) : $response->body();
            $out[$text] = "$dir/$i.".($provider === 'elevenlabs' ? 'mp3' : 'wav');
            File::put($out[$text], $audio);
            $this->cost += mb_strlen($text) / 1000 * Voices::price("$provider:$id");
        }

        return $out;
    }

    /**
     * Find the WAV audio in a Gemini Interactions response.
     *
     * @throws RuntimeException When there is none.
     */
    protected function geminiAudio(array $response): string
    {
        $data = null;
        array_walk_recursive($response, function ($value, $key) use (&$data) {
            if ($data === null && $key === 'data' && is_string($value) && strlen($value) > 64) {
                $data = $value;
            }
        });

        $audio = $data !== null ? base64_decode($data, true) : false;
        if ($audio === false || $audio === '') {
            throw new RuntimeException('Gemini answered without audio.');
        }

        return $audio;
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
