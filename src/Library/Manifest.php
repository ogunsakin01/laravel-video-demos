<?php

namespace Ogunsakin01\VideoDemo\Library;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The list of published videos, stored as JSON on the library disk next to the
 * videos. The studio writes it when you publish; the library pages read it,
 * so production needs only the disk, not the studio's database.
 */
class Manifest
{
    protected const CACHE_KEY = 'video-demo.manifest';

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, (int) config('video-demo.library.cache_seconds', 300), function () {
            $disk = $this->disk();

            return $disk->exists($this->path())
                ? (json_decode($disk->get($this->path()), true)['videos'] ?? [])
                : [];
        });
    }

    /** Videos the given library area shows: public shows public videos, private shows all */
    public function forArea(string $area): array
    {
        return array_values(array_filter(
            $this->all(),
            fn ($v) => $area === 'private' || ($v['visibility'] ?? 'public') === 'public',
        ));
    }

    public function find(string $slug, string $area): ?array
    {
        return collect($this->forArea($area))->firstWhere('slug', $slug);
    }

    /** Videos matching a search, best match first */
    public function search(string $query, string $area): array
    {
        $words = array_filter(preg_split('/\W+/u', Str::lower($query)), fn ($w) => strlen($w) > 2);

        if ($words === []) {
            return $this->forArea($area);
        }

        return collect($this->forArea($area))
            ->map(function ($video) use ($words) {
                $title = Str::lower($video['title'] ?? '');
                $rest = Str::lower(($video['summary'] ?? '').' '.collect($video['chapters'] ?? [])->pluck('title')->implode(' '));
                $score = 0;
                foreach ($words as $word) {
                    $score += substr_count($title, $word) * 3 + substr_count($rest, $word);
                }

                return [$score, $video];
            })
            ->filter(fn ($pair) => $pair[0] > 0)
            ->sortByDesc(fn ($pair) => $pair[0])
            ->map(fn ($pair) => $pair[1])
            ->values()
            ->all();
    }

    /** Every file a manifest entry points to */
    public static function files(array $video): array
    {
        return array_values(array_unique(array_filter([
            $video['video'] ?? null,
            $video['poster'] ?? null,
            ...collect($video['sources'] ?? [])->flatMap(fn ($s) => [$s['video'] ?? null, $s['poster'] ?? null])->all(),
        ])));
    }

    /**
     * The versions of a video, keyed by device, each with its own size, length
     * and chapters. Videos published before versions existed have one.
     *
     * @return array<string, array{video: string, poster: string, width: int, height: int, duration: float, chapters: array}>
     */
    public static function sources(array $video): array
    {
        return $video['sources'] ?? ['desktop' => [
            'video' => $video['video'], 'poster' => $video['poster'], 'width' => 1280, 'height' => 800,
            'duration' => $video['duration'] ?? 0, 'chapters' => $video['chapters'] ?? [],
        ]];
    }

    /** Address of a video's MP4 or poster; private videos get a temporary link where the disk supports one */
    public function url(array $video, string $kind = 'video', ?string $device = null): string
    {
        $path = $device ? (static::sources($video)[$device][$kind] ?? $video[$kind]) : $video[$kind];
        $disk = $this->disk();

        if (($video['visibility'] ?? 'public') === 'private') {
            try {
                return $disk->temporaryUrl($path, now()->addHours(2));
            } catch (Throwable) {
                // Disks without temporary links serve the plain address
            }
        }

        return static::relative($disk->url($path), (string) config('video-demo.storage.disk'));
    }

    /**
     * A local disk's address is built from APP_URL, so it breaks when the
     * site is opened at another address (http://app.test vs https://app.test).
     * Its path alone works at any of them.
     */
    public static function relative(string $url, string $disk): string
    {
        if (config("filesystems.disks.$disk.driver") !== 'local') {
            return $url;
        }

        $app = rtrim((string) config('app.url'), '/');
        if ($app !== '' && str_starts_with($url, $app.'/')) {
            return substr($url, strlen($app));
        }

        // Disks with an address on another host, such as a CDN, are left alone
        return $url;
    }

    public function put(array $video): void
    {
        $videos = collect($this->fresh())->reject(fn ($v) => $v['slug'] === $video['slug'])->push($video);
        $this->write($videos->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)->values()->all());
    }

    public function remove(string $slug): ?array
    {
        $videos = collect($this->fresh());
        $removed = $videos->firstWhere('slug', $slug);
        $this->write($videos->reject(fn ($v) => $v['slug'] === $slug)->values()->all());

        return $removed;
    }

    public function path(string $file = 'manifest.json'): string
    {
        return trim(config('video-demo.storage.path'), '/').'/'.$file;
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk(config('video-demo.storage.disk'));
    }

    protected function fresh(): array
    {
        Cache::forget(self::CACHE_KEY);

        return $this->all();
    }

    protected function write(array $videos): void
    {
        $this->disk()->put($this->path(), json_encode(['videos' => $videos], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Cache::forget(self::CACHE_KEY);
    }
}
