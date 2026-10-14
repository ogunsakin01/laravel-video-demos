<?php

namespace Ogunsakin01\VideoDemo\Studio;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Finds where the app's code shows a video: a player tag such as
 * <x-video-demo::player slug="post-a-job" />, the address of its published
 * file, or its slug next to the word "video". Used to warn before a video is
 * deleted, since those pages would then show nothing.
 */
class Embeds
{
    /**
     * The folders searched, relative to the app's root.
     */
    protected const FOLDERS = ['resources', 'app', 'routes'];

    /**
     * The most places listed for one video.
     */
    protected const LIMIT = 20;

    /**
     * Find where each of the given videos is shown.
     *
     * @param  list<string>  $slugs
     * @return array<string, list<array{file: string, line: int, text: string}>>
     */
    public static function find(array $slugs): array
    {
        $slugs = array_values(array_filter(array_unique($slugs)));
        $found = array_fill_keys($slugs, []);
        $folders = array_values(array_filter(array_map(base_path(...), self::FOLDERS), 'is_dir'));

        if ($slugs === [] || $folders === []) {
            return $found;
        }

        $files = Finder::create()->files()->in($folders)
            ->name(['*.php', '*.js', '*.ts', '*.jsx', '*.tsx', '*.vue', '*.svelte', '*.html', '*.md'])
            ->exclude(['vendor', 'node_modules'])
            ->notPath('views/vendor/video-demo')
            ->size('< 512K');

        foreach ($files as $file) {
            $contents = $file->getContents();
            $mentioned = array_filter($slugs, fn ($slug) => str_contains($contents, $slug) && count($found[$slug]) < self::LIMIT);
            if ($mentioned === []) {
                continue;
            }

            foreach (explode("\n", $contents) as $i => $line) {
                foreach ($mentioned as $slug) {
                    if (static::shows($line, $slug) && count($found[$slug]) < self::LIMIT) {
                        $found[$slug][] = [
                            'file' => ltrim(Str::after($file->getPathname(), base_path()), '/'),
                            'line' => $i + 1,
                            'text' => Str::limit(trim($line), 160),
                        ];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Determine if a line of code shows the video.
     */
    protected static function shows(string $line, string $slug): bool
    {
        if (! str_contains($line, $slug)) {
            return false;
        }

        $q = preg_quote($slug, '~');

        return (bool) preg_match('~slug\s*=\s*["\']'.$q.'["\']|video-demo/'.$q.'-v\d~', $line)
            || (preg_match('~["\']'.$q.'["\']~', $line) && stripos($line, 'video') !== false);
    }
}
