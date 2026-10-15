<?php

namespace Ogunsakin01\VideoDemo\Voice;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use RuntimeException;

/**
 * The background music tracks a video can use: your own files, listed in
 * video-demo.music.tracks, and tracks uploaded on the studio's Styles page,
 * kept on the work disk.
 */
class Music
{
    /**
     * The file types a track may be.
     */
    public const TYPES = ['mp3', 'wav', 'm4a', 'ogg', 'flac'];

    /**
     * Get every track, key => ['label' => …, 'file' => absolute path, 'uploaded' => bool].
     *
     * @return array<string, array{label: string, file: string, uploaded: bool}>
     */
    public static function tracks(): array
    {
        $tracks = [];
        foreach ((array) config('video-demo.music.tracks', []) as $key => $file) {
            if (is_string($file) && is_file($file)) {
                $tracks[(string) $key] = ['label' => Str::headline((string) $key), 'file' => $file, 'uploaded' => false];
            }
        }

        $dir = static::dir();
        foreach (is_dir($dir) ? File::files($dir) : [] as $file) {
            if (in_array(strtolower($file->getExtension()), self::TYPES, true)) {
                $key = $file->getFilenameWithoutExtension();
                $tracks[$key] ??= ['label' => Str::headline($key), 'file' => $file->getPathname(), 'uploaded' => true];
            }
        }
        ksort($tracks);

        return $tracks;
    }

    /**
     * Get the tracks to choose from, key => label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn ($t) => $t['label'], static::tracks());
    }

    /**
     * Get a track, or null when there is no such track.
     *
     * @return ?array{label: string, file: string, uploaded: bool}
     */
    public static function find(?string $key): ?array
    {
        return $key === null || $key === '' ? null : (static::tracks()[$key] ?? null);
    }

    /**
     * Get a track's label, or "No music".
     */
    public static function label(?string $key): string
    {
        return static::find($key)['label'] ?? ($key ? $key : 'No music');
    }

    /**
     * Keep an uploaded track, named after its file, and return its key.
     *
     * @throws RuntimeException When the file isn't a track.
     */
    public static function upload(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::TYPES, true)) {
            throw new RuntimeException('Upload an MP3, WAV, M4A, OGG or FLAC file.');
        }

        $key = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'track';
        File::ensureDirectoryExists(static::dir());
        foreach (self::TYPES as $old) {
            File::delete(static::dir()."/$key.$old");
        }
        $file->move(static::dir(), "$key.$extension");

        return $key;
    }

    /**
     * Delete an uploaded track.
     */
    public static function delete(string $key): void
    {
        $track = static::find($key);
        if ($track && $track['uploaded']) {
            File::delete($track['file']);
        }
    }

    /**
     * Get the folder uploaded tracks are kept in, on the work disk.
     */
    public static function dir(): string
    {
        return Recorder::workDir(trim((string) config('video-demo.storage.work_path'), '/').'/music');
    }
}
