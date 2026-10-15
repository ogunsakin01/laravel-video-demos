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
     * Lines of code shown around the pasted line.
     */
    protected const AROUND = 3;

    /**
     * Work out where to paste a snippet in the view that renders a section:
     * the section's opening tag is found above its heading (matching its tag
     * and a class), and the snippet goes just before it, first inside it, or
     * just after its closing tag. Returns the file, the line the snippet will
     * be on, whether that place is exact (or only near the heading), and the
     * lines around it with the snippet in place. Null when no view shows the
     * heading.
     *
     * @param  list<string>  $classes  The section's classes, to tell its tag from others like it.
     * @return ?array{file: string, line: int, exact: bool, excerpt: list<array{n: ?int, text: string, added: bool}>}
     */
    public static function placement(string $heading, string $path, string $tag, array $classes, string $position, string $snippet): ?array
    {
        $found = static::find($heading, $path);
        if ($found === null) {
            return null;
        }

        $lines = explode("\n", (string) file_get_contents(base_path($found['file'])));
        $headingAt = $found['line'] - 1;

        // A whole page's wrapper (main) is opened by the layout, not this view:
        // the player goes first inside the page's content.
        if (strtolower($tag) === 'main' && ($content = static::pageContent($lines)) !== null) {
            return static::result($found['file'], $lines, $content, static::innerIndent($lines, $content - 1), $snippet, true);
        }

        $open = static::openingTag($lines, $headingAt, $tag, $classes);

        if ($open === null) {
            // Only the heading is known: paste just above it.
            return static::result($found['file'], $lines, $headingAt, static::indent($lines[$headingAt]), $snippet, false);
        }

        $indent = static::indent($lines[$open]);
        if ($position === 'before') {
            return static::result($found['file'], $lines, $open, $indent, $snippet, true);
        }
        if ($position === 'after') {
            $close = static::closingTag($lines, $open, $tag);

            return $close === null
                ? static::result($found['file'], $lines, $headingAt, static::indent($lines[$headingAt]), $snippet, false)
                : static::result($found['file'], $lines, $close + 1, $indent, $snippet, true);
        }

        // First thing inside: after the line the opening tag ends on, indented like what follows.
        $end = static::tagEnd($lines, $open);

        return static::result($found['file'], $lines, $end + 1, static::innerIndent($lines, $end), $snippet, true);
    }

    /**
     * Find where a page's own content starts: the line after its layout
     * component opens (<x-app-layout>, <x-layouts.app …>) or after
     * the Blade section named "content" starts. Null when the view has neither.
     */
    protected static function pageContent(array $lines): ?int
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/<x-[\w.:-]*layout\b/i', $line)) {
                return static::tagEnd($lines, $i) + 1;
            }
            if (preg_match('/@section\(\s*[\'"]content[\'"]\s*\)/', $line)) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * Find the line a tag opened on $open ends on, for tags written over several lines.
     */
    protected static function tagEnd(array $lines, int $open): int
    {
        $end = $open;
        while ($end < count($lines) - 1 && ! str_contains(preg_replace('/"[^"]*"|\'[^\']*\'/', '', $lines[$end]) ?? '', '>')) {
            $end++;
        }

        return $end;
    }

    /**
     * Get the indent of what follows line $at, or one step deeper than it.
     */
    protected static function innerIndent(array $lines, int $at): string
    {
        $outer = static::indent($lines[$at] ?? '');
        $inner = static::indent($lines[$at + 1] ?? '');

        return strlen($inner) > strlen($outer) ? $inner : $outer.'    ';
    }

    /**
     * Find the line that opens the section, searching up from its heading.
     */
    protected static function openingTag(array $lines, int $from, string $tag, array $classes): ?int
    {
        $nearest = null;
        for ($i = $from; $i >= max(0, $from - 30); $i--) {
            if (! preg_match('/<'.preg_quote($tag, '/').'[\s>]/i', $lines[$i])) {
                continue;
            }
            if ($classes === [] || array_filter($classes, fn ($c) => str_contains($lines[$i], $c)) !== []) {
                return $i;
            }
            $nearest ??= $i;
        }

        // A tag other than a plain div is rarely ambiguous.
        return strtolower($tag) !== 'div' ? $nearest : null;
    }

    /**
     * Find the line that closes the tag opened on $open.
     */
    protected static function closingTag(array $lines, int $open, string $tag): ?int
    {
        $depth = 0;
        $q = preg_quote($tag, '/');
        for ($i = $open; $i < count($lines); $i++) {
            $depth += preg_match_all('/<'.$q.'[\s>]/i', $lines[$i]) - preg_match_all('/<\/'.$q.'\s*>/i', $lines[$i]);
            if ($i > $open && $depth <= 0) {
                return $i;
            }
            if ($i === $open && $depth <= 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return array{file: string, line: int, exact: bool, excerpt: list<array{n: ?int, text: string, added: bool}>}
     */
    protected static function result(string $file, array $lines, int $at, string $indent, string $snippet, bool $exact): array
    {
        $excerpt = [];
        for ($i = max(0, $at - self::AROUND); $i < $at; $i++) {
            $excerpt[] = ['n' => $i + 1, 'text' => rtrim($lines[$i]), 'added' => false];
        }
        $excerpt[] = ['n' => $at + 1, 'text' => $indent.$snippet, 'added' => true];
        for ($i = $at; $i < min(count($lines), $at + self::AROUND); $i++) {
            $excerpt[] = ['n' => $i + 2, 'text' => rtrim($lines[$i]), 'added' => false];
        }

        return ['file' => $file, 'line' => $at + 1, 'exact' => $exact, 'excerpt' => $excerpt];
    }

    protected static function indent(string $line): string
    {
        return (string) preg_replace('/\S.*$/s', '', $line);
    }

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
            // Text shown on the page beats the same words in an attribute, such as a title="…".
            $lines = explode("\n", $contents);
            $quoted = '/>\s*('.preg_quote($text, '/').'|'.preg_quote(e($text), '/').')\s*(<|$)/';
            $hits = array_keys(array_filter($lines, fn ($l) => preg_match($quoted, $l)))
                ?: array_keys(array_filter($lines, fn ($l) => str_contains($l, $text) || str_contains($l, e($text))));
            if ($hits === []) {
                continue;
            }
            $score += preg_match($quoted, $lines[$hits[0]]) ? 2 : 0;
            if ($best === null || $score > $best['score']) {
                $best = [
                    'file' => ltrim(Str::after($file->getPathname(), base_path()), '/'),
                    'line' => $hits[0] + 1,
                    'text' => Str::limit(trim($lines[$hits[0]]), 160),
                    'score' => $score,
                ];
            }
        }

        if ($best === null) {
            return null;
        }
        unset($best['score']);

        return $best;
    }
}
