<?php

namespace Ogunsakin01\VideoDemo\Voice;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The voices a video can be narrated in: only the ones that work here right
 * now. Local voices (Kokoro) once video-demo:install-voice has run; the
 * computer's own voice when it has one (macOS "say", Windows speech,
 * espeak-ng on Linux); and OpenAI, Gemini and ElevenLabs voices when their
 * key is set. A voice is stored as "provider:id", such as "kokoro:af_heart",
 * "system:Samantha" or "openai:marin".
 */
class Voices
{
    /**
     * The computer's voices worth offering. macOS also has novelty voices
     * (Bells, Boing…), which are left out.
     */
    protected const SYSTEM_VOICES = ['Samantha', 'Daniel', 'Karen', 'Moira', 'Tessa', 'Rishi', 'Fiona', 'Alex', 'Ava', 'Tom', 'Allison', 'Susan', 'Serena', 'Kate', 'Oliver', 'Lee'];

    /**
     * The computer's voices, found once per process.
     *
     * @var ?array<string, string>
     */
    protected static ?array $system = null;

    /**
     * Get the voices to choose from, key => label, grouped by where they run.
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        $groups = [];

        if (static::localInstalled()) {
            foreach ((array) config('video-demo.voice.kokoro_voices', []) as $id => $label) {
                $groups['Local voices, free'][self::key('kokoro', $id)] = $label;
            }
        }

        foreach (static::systemVoices() as $id => $label) {
            $groups["This computer's voice, for drafts"][self::key('system', $id)] = $label;
        }

        foreach (['openai' => 'OpenAI', 'gemini' => 'Gemini', 'elevenlabs' => 'ElevenLabs'] as $provider => $name) {
            if (! static::providerKey($provider)) {
                continue;
            }
            $voices = $provider === 'elevenlabs' ? static::elevenLabsVoices() : (array) config("video-demo.voice.providers.$provider.voices", []);
            foreach ($voices as $id => $label) {
                $groups[sprintf('%s voices, about $%.2f a minute', $name, static::pricePerMinute($provider))][self::key($provider, (string) $id)] = $label;
            }
        }

        return $groups;
    }

    /**
     * Get a provider's API key: from video-demo.voice.providers, or from
     * Prism's config when the app has set it there.
     */
    public static function providerKey(string $provider): ?string
    {
        $key = config("video-demo.voice.providers.$provider.key") ?: config("prism.providers.$provider.api_key");

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Get what a voice costs per 1,000 characters spoken, in US dollars; 0 for
     * the free local and computer voices.
     */
    public static function price(?string $key): float
    {
        [$provider] = static::parse((string) $key);

        return (float) config("video-demo.voice.providers.$provider.price_per_1k_chars", 0);
    }

    /**
     * Get what a provider's voices cost per minute of speech, at about 900
     * characters a minute.
     */
    public static function pricePerMinute(string $provider): float
    {
        return static::price("$provider:") * 0.9;
    }

    /**
     * Get the voices in the ElevenLabs account, id => name, kept for an hour.
     * Empty when the account can't be reached.
     *
     * @return array<string, string>
     */
    public static function elevenLabsVoices(): array
    {
        $key = static::providerKey('elevenlabs');
        if (! $key) {
            return [];
        }

        return Cache::remember('video-demo.elevenlabs-voices.'.sha1($key), 3600, function () use ($key) {
            try {
                $voices = Http::withHeaders(['xi-api-key' => $key])->timeout(15)
                    ->get('https://api.elevenlabs.io/v2/voices', ['page_size' => 100, 'sort' => 'name', 'sort_direction' => 'asc'])
                    ->throw()->json('voices', []);
            } catch (Throwable) {
                return [];
            }

            $list = [];
            foreach ($voices as $voice) {
                if (! empty($voice['voice_id'])) {
                    $kind = ($voice['category'] ?? '') === 'premade' ? '' : ' (yours)';
                    $list[(string) $voice['voice_id']] = ($voice['name'] ?? $voice['voice_id']).$kind;
                }
            }

            return $list;
        });
    }

    /**
     * Get every voice that can be used, key => label.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(static::options()) ?: [[]]);
    }

    /**
     * Determine if a voice can be used on this machine.
     */
    public static function available(?string $key): bool
    {
        return $key !== null && $key !== '' && array_key_exists($key, static::all());
    }

    /**
     * Get a voice's label, such as "Heart, warm (US)", or the key when it is unknown.
     */
    public static function label(?string $key): string
    {
        if ($key === null || $key === '') {
            return 'No voice';
        }

        return static::all()[$key] ?? $key;
    }

    /**
     * Split a voice key into its provider and id.
     *
     * @return array{0: string, 1: string}
     */
    public static function parse(string $key): array
    {
        [$provider, $id] = array_pad(explode(':', $key, 2), 2, '');

        return [$provider, $id];
    }

    /**
     * Determine if the local voice (Kokoro) is installed for the recorder.
     */
    public static function localInstalled(): bool
    {
        $modules = (string) config('video-demo.recorder.node_modules');

        return $modules !== '' && is_file("$modules/kokoro-js/package.json");
    }

    /**
     * Get the folder the local voice model is kept in: outside the project, so
     * it is never committed and is shared by every project on this machine.
     */
    public static function modelCache(): string
    {
        if ($configured = config('video-demo.voice.cache_path')) {
            return (string) $configured;
        }

        $base = getenv('XDG_CACHE_HOME') ?: (getenv('LOCALAPPDATA') ?: (getenv('HOME') ? getenv('HOME').'/.cache' : sys_get_temp_dir()));

        return rtrim($base, '/\\').'/video-demo/voices';
    }

    /**
     * Get the computer's own voices, id => label.
     *
     * @return array<string, string>
     */
    public static function systemVoices(): array
    {
        return static::$system ??= static::findSystemVoices();
    }

    /**
     * Forget the voices found, or set them, such as in tests: the next call
     * looks again when given null.
     *
     * @param  ?array<string, string>  $voices
     */
    public static function flush(?array $voices = null): void
    {
        static::$system = $voices;
    }

    /**
     * Ask the computer which voices it has.
     *
     * @return array<string, string>
     */
    protected static function findSystemVoices(): array
    {
        try {
            if (PHP_OS_FAMILY === 'Darwin') {
                $voices = [];
                foreach (explode("\n", Process::timeout(10)->run(['say', '-v', '?'])->output()) as $line) {
                    // "Samantha            en_US    # Hello! My name is Samantha."
                    if (preg_match('/^(.+?)\s{2,}(en_[A-Z]{2})\s+#/', $line, $m)) {
                        $name = trim($m[1]);
                        $base = preg_replace('/\s*\((Enhanced|Premium)\)$/', '', $name);
                        if (in_array($base, self::SYSTEM_VOICES, true)) {
                            $voices[$name] = "$name (".str_replace('_', ' ', $m[2]).')';
                        }
                    }
                }

                return $voices;
            }

            if (PHP_OS_FAMILY === 'Windows') {
                $names = Process::timeout(15)->run(['powershell', '-NoProfile', '-Command',
                    'Add-Type -AssemblyName System.Speech; (New-Object System.Speech.Synthesis.SpeechSynthesizer).GetInstalledVoices() | ForEach-Object { $_.VoiceInfo.Name }'])->output();

                return collect(explode("\n", $names))->map(fn ($n) => trim($n))->filter()->mapWithKeys(fn ($n) => [$n => $n])->all();
            }

            // Linux and others: espeak-ng, when it is installed.
            if (Process::timeout(10)->run(['espeak-ng', '--version'])->successful()) {
                return ['en-us' => 'English (US)', 'en-gb' => 'English (UK)'];
            }
        } catch (Throwable) {
            // No speech tool on this machine.
        }

        return [];
    }

    protected static function key(string $provider, string $id): string
    {
        return "$provider:$id";
    }
}
