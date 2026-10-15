<?php

namespace Ogunsakin01\VideoDemo\Voice;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The voices a video can be narrated in: only the ones that work on this
 * machine right now. Local voices (Kokoro) once video-demo:install-voice has
 * run; the computer's own voice when it has one (macOS "say", Windows speech,
 * espeak-ng on Linux). A voice is stored as "provider:id", such as
 * "kokoro:af_heart" or "system:Samantha".
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

        return $groups;
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
