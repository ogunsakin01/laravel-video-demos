<?php

namespace Ogunsakin01\VideoDemo\Voice;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Support\Language;
use Throwable;

/**
 * The voices a video can be narrated in: only the ones that work here right
 * now. Local voices (Kokoro) once video-demo:install-voice has run; the
 * computer's own voice when it has one (macOS "say", Windows speech,
 * espeak-ng on Linux); and OpenAI, Gemini and ElevenLabs voices when their
 * key is set. A voice is stored as "provider:id", such as "kokoro:af_heart",
 * "system:Samantha" or "openai:marin".
 *
 * Each voice speaks some languages: the local voices English only, the
 * computer's voices their own language, and the cloud voices nearly any.
 */
class Voices
{
    /**
     * The computer's voices worth offering. macOS also has novelty voices
     * (Bells, Boing…), which are left out.
     */
    protected const SYSTEM_VOICES = ['Samantha', 'Daniel', 'Karen', 'Moira', 'Tessa', 'Rishi', 'Fiona', 'Alex', 'Ava', 'Tom', 'Allison', 'Susan', 'Serena', 'Kate', 'Oliver', 'Lee'];

    /**
     * The macOS voices that come in every language, such as "Eddy (French
     * (France))". Each language has its own voices too, so these are left out.
     */
    protected const EVERY_LANGUAGE_VOICES = ['Eddy', 'Flo', 'Grandma', 'Grandpa', 'Reed', 'Rocko', 'Sandy', 'Shelley'];

    /**
     * ElevenLabs models that speak only English; the others speak many languages.
     */
    protected const ENGLISH_ONLY_MODELS = ['eleven_monolingual_v1', 'eleven_turbo_v2', 'eleven_flash_v2', 'eleven_english_sts_v2'];

    /**
     * The computer's voices, found once per process.
     *
     * @var ?array<string, string>
     */
    protected static ?array $system = null;

    /**
     * The language each of the computer's voices speaks, id => code.
     *
     * @var array<string, string>
     */
    protected static array $systemLanguages = [];

    /**
     * Get the voices to choose from, key => label, grouped by where they run.
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        $groups = [];

        // The most natural first: the cloud voices sound most like a person.
        foreach (['openai' => 'OpenAI', 'gemini' => 'Gemini', 'elevenlabs' => 'ElevenLabs'] as $provider => $name) {
            if (! static::providerKey($provider)) {
                continue;
            }
            $voices = $provider === 'elevenlabs' ? static::elevenLabsVoices() : (array) config("video-demo.voice.providers.$provider.voices", []);
            foreach ($voices as $id => $label) {
                $groups[sprintf('%s voices, about $%.2f a minute', $name, static::pricePerMinute($provider))][self::key($provider, (string) $id)] = $label;
            }
        }

        if (static::localInstalled()) {
            foreach ((array) config('video-demo.voice.kokoro_voices', []) as $id => $label) {
                $groups['Local voices, free'][self::key('kokoro', $id)] = $label;
            }
        }

        foreach (static::systemVoices() as $id => $label) {
            $groups["This computer's voice: robotic, for drafts only"][self::key('system', $id)] = $label;
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
     * Get the languages a voice speaks, as codes without a region such as
     * "en"; ["*"] for a voice that speaks nearly any language.
     *
     * @return list<string>
     */
    public static function languages(?string $key): array
    {
        [$provider, $id] = static::parse((string) $key);

        return match ($provider) {
            // Kokoro's pronunciation, as bundled for Node, knows English only.
            'kokoro' => ['en'],
            'system' => isset(static::systemVoices()[$id], static::$systemLanguages[$id]) ? [Language::base(static::$systemLanguages[$id])] : ['*'],
            'elevenlabs' => in_array(config('video-demo.voice.providers.elevenlabs.model'), self::ENGLISH_ONLY_MODELS, true) ? ['en'] : ['*'],
            default => ['*'],
        };
    }

    /**
     * Determine if a voice speaks the given language.
     */
    public static function speaks(?string $key, ?string $language): bool
    {
        $languages = static::languages($key);

        return in_array('*', $languages, true) || in_array(Language::base($language), $languages, true);
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

        // A local voice keeps its name on machines where it isn't installed.
        [$provider, $id] = static::parse($key);

        return static::all()[$key]
            ?? ($provider === 'kokoro' ? ((array) config('video-demo.voice.kokoro_voices', []))[$id] ?? null : null)
            ?? $key;
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
        if (static::$system === null) {
            $found = static::findSystemVoices();
            static::$system = array_map(fn ($voice) => $voice[0], $found);
            static::$systemLanguages = array_filter(array_map(fn ($voice) => $voice[1], $found));
        }

        return static::$system;
    }

    /**
     * Forget the voices found, or set them, such as in tests: the next call
     * looks again when given null. $languages gives each voice's language,
     * id => code.
     *
     * @param  ?array<string, string>  $voices
     * @param  array<string, string>  $languages
     */
    public static function flush(?array $voices = null, array $languages = []): void
    {
        static::$system = $voices;
        static::$systemLanguages = $languages;
    }

    /**
     * Ask the computer which voices it has, id => [label, language code].
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    protected static function findSystemVoices(): array
    {
        try {
            if (PHP_OS_FAMILY === 'Darwin') {
                $voices = [];
                foreach (explode("\n", Process::timeout(10)->run(['say', '-v', '?'])->output()) as $line) {
                    // "Samantha            en_US    # Hello! My name is Samantha."
                    if (preg_match('/^(.+?)\s+([a-z]{2,3})_([A-Z0-9]{2,3})\s+#/', $line, $m)) {
                        $name = trim($m[1]);
                        $base = preg_replace('/\s*\(.*\)$/', '', $name);
                        // English has novelty voices (Bells, Boing…), which are left out.
                        if ($m[2] === 'en' ? in_array($base, self::SYSTEM_VOICES, true) : ! in_array($base, self::EVERY_LANGUAGE_VOICES, true)) {
                            $voices[$name] = ["$name ($m[2] $m[3])", "$m[2]-$m[3]"];
                        }
                    }
                }

                return $voices;
            }

            if (PHP_OS_FAMILY === 'Windows') {
                $names = Process::timeout(15)->run(['powershell', '-NoProfile', '-Command',
                    'Add-Type -AssemblyName System.Speech; (New-Object System.Speech.Synthesis.SpeechSynthesizer).GetInstalledVoices() | ForEach-Object { $_.VoiceInfo.Name + "|" + $_.VoiceInfo.Culture.Name }'])->output();

                return collect(explode("\n", $names))->map(fn ($n) => explode('|', trim($n), 2))->filter(fn ($n) => $n[0] !== '')
                    ->mapWithKeys(fn ($n) => [$n[0] => [$n[0], Language::normalize($n[1] ?? null)]])->all();
            }

            // Linux and others: espeak-ng, when it is installed, in each language it has.
            $list = Process::timeout(10)->run(['espeak-ng', '--voices']);
            if ($list->successful()) {
                $voices = [];
                foreach (explode("\n", $list->output()) as $line) {
                    // " 5  fr-fr           --/M      French_(France)    roa/fr"
                    if (preg_match('/^\s*\d+\s+(\S+)\s/', $line, $m) && ($code = Language::normalize($m[1]))) {
                        $voices[$m[1]] = [Language::name($code), $code];
                    }
                }

                return $voices;
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
