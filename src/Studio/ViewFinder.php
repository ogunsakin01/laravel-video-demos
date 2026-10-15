<?php

namespace Ogunsakin01\VideoDemo\Studio;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Finds the line of a view that renders a section of a page, from the text
 * the section shows, such as its heading. Used to say where to paste a
 * video's player tag.
 */
class ViewFinder
{
    /**
     * The folders searched, relative to the app's root.
     */
    protected const FOLDERS = ['resources/views', 'resources/js'];

    /**
     * Find the file and line that show the given text, preferring files whose
     * name shares a word with the page's path. Null when no view shows it, as
     * when the text comes from the database or a translation file.
     *
     * @return ?array{file: string, line: int, text: string}
     */
    public static function find(string $text, string $path = '/'): ?array
    {
        $text = trim($text);
        $folders = array_values(array_filter(array_map(base_path(...), self::FOLDERS), 'is_dir'));
        if (mb_strlen($text) < 3 || $folders === []) {
            return null;
        }

        $files = Finder::create()->files()->in($folders)
            ->name(['*.blade.php', '*.php', '*.vue', '*.jsx', '*.tsx', '*.svelte', '*.html'])
            ->exclude(['vendor'])
            ->size('< 512K');

        $words = array_filter(preg_split('/[^a-z0-9]+/', Str::lower($path)) ?: [], fn ($w) => strlen($w) > 2);
        $best = null;
        foreach ($files as $file) {
            $contents = $file->getContents();
            if (! str_contains($contents, $text) && ! str_contains($contents, e($text))) {
                continue;
            }

            $name = Str::lower($file->getRelativePathname());
            $score = count(array_filter($words, fn ($w) => str_contains($name, Str::singular($w))));
            foreach (explode("\n", $contents) as $i => $line) {
                if (str_contains($line, $text) || str_contains($line, e($text))) {
                    if ($best === null || $score > $best['score']) {
                        $best = [
                            'file' => ltrim(Str::after($file->getPathname(), base_path()), '/'),
                            'line' => $i + 1,
                            'text' => Str::limit(trim($line), 160),
                            'score' => $score,
                        ];
                    }
                    break;
                }
            }
        }

        if ($best === null) {
            return null;
        }
        unset($best['score']);

        return $best;
    }
}
