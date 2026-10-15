<?php

namespace Ogunsakin01\VideoDemo\Voice;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use RuntimeException;

/**
 * The background music tracks a video can use: the package's own built-in
 * tracks, your own files listed in video-demo.music.tracks, and tracks
 * uploaded on the studio's Styles page, kept on the work disk.
 */
class Music
{
    /**
     * The file types a track may be.
     */
    public const TYPES = ['mp3', 'wav', 'm4a', 'ogg', 'flac'];

    /**
     * The built-in tracks: composed for the package and free to use (CC0).
     */
    public const BUILT_IN = ['calm' => 'Calm', 'upbeat' => 'Upbeat', 'corporate' => 'Corporate', 'cinematic' => 'Cinematic'];

    /**
     * Where the built-in tracks are downloaded from: the package's repository,
     * through a CDN, at the release that added them. They aren't part of the
     * Composer download.
     */
    public const BUILT_IN_URL = 'https://cdn.jsdelivr.net/gh/ogunsakin01/laravel-video-demos@v0.12.0/docs/music';

    /**
     * Get every track, key => ['label' => …, 'file' => absolute path, 'uploaded' => bool].
     * A built-in track's file is only there once local() has downloaded it.
     *
     * @return array<string, array{label: string, file: string, uploaded: bool}>
     */
    public static function tracks(): array
    {
        $tracks = [];
        if (config('video-demo.music.builtin', true)) {
            foreach (self::BUILT_IN as $name => $label) {
                $tracks["builtin:$name"] = ['label' => "$label (built in)", 'file' => static::dir()."/built-in/$name.mp3", 'uploaded' => false];
            }
        }
        foreach ((array) config('video-demo.music.tracks', []) as $key => $file) {
            if (is_string($file) && is_file($file)) {
                $tracks[(string) $key] = ['label' => Str::headline((string) $key), 'file' => $file, 'uploaded' => false];
            }
        }

        $dir = static::dir();
        $own = [];
        foreach (is_dir($dir) ? File::files($dir) : [] as $file) {
            if (in_array(strtolower($file->getExtension()), self::TYPES, true)) {
                $key = $file->getFilenameWithoutExtension();
                $own[$key] ??= ['label' => Str::headline($key), 'file' => $file->getPathname(), 'uploaded' => true];
            }
        }
        ksort($own);

        // Your own tracks first, then the built-in ones.
        return [...array_filter($tracks, fn ($t, $k) => ! str_starts_with($k, 'builtin:'), ARRAY_FILTER_USE_BOTH), ...$own, ...array_filter($tracks, fn ($t, $k) => str_starts_with($k, 'builtin:'), ARRAY_FILTER_USE_BOTH)];
    }

    /**
     * Get the path of a track's file on this machine, downloading a built-in
     * track the first time it is used.
     *
     * @throws RuntimeException When there is no such track, or it can't be downloaded.
     */
    public static function local(string $key): string
    {
        $track = static::find($key) ?? throw new RuntimeException("There is no music track [$key].");
        if (is_file($track['file'])) {
            return $track['file'];
        }

        $name = substr($key, strlen('builtin:'));
        $url = rtrim((string) config('video-demo.music.builtin_url', self::BUILT_IN_URL), '/')."/$name.mp3";
        File::ensureDirectoryExists(dirname($track['file']));
        $response = Http::timeout(60)->sink($track['file'].'.part')->get($url);
        if ($response->failed()) {
            File::delete($track['file'].'.part');

            throw new RuntimeException("The built-in track [$name] couldn't be downloaded (HTTP {$response->status()}) from $url.");
        }
        File::move($track['file'].'.part', $track['file']);

        return $track['file'];
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
