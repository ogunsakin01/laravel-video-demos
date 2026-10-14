<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * The tools Claude uses to read the app while planning. Reading is limited to
 * the folders in video-demo.ai.code.paths, and files matching
 * video-demo.ai.code.deny (such as .env) are never returned.
 */
class CodeTools
{
    protected const MAX_LISTED = 400;

    protected const MAX_MATCHES = 60;

    protected Redactor $redactor;

    public function __construct(protected ?string $root = null, ?Redactor $redactor = null)
    {
        $this->redactor = $redactor ?? new Redactor;
        $root ??= base_path();
        $this->root = rtrim(realpath($root) ?: $root, '/');
    }

    /** Tool definitions: name, description and a JSON schema for the input */
    public function definitions(): array
    {
        $paths = implode(', ', $this->allowedPaths());

        return [
            [
                'name' => 'list_files',
                'description' => "List the files under a folder of the app, relative to its root. Readable folders: $paths.",
                'schema' => [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => 'Folder, for example resources/views/jobs']],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'read_file',
                'description' => 'Read a file of the app, with line numbers. Long files are cut; ask for later lines with from_line.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'from_line' => ['type' => 'integer', 'description' => 'First line to return; 1 for the start of the file'],
                    ],
                    'required' => ['path', 'from_line'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'search_code',
                'description' => 'Find lines matching a case-insensitive regular expression in the readable folders, for example a button label or a route name.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => ['type' => 'string'],
                        'path' => ['type' => 'string', 'description' => 'Limit the search to this folder; empty to search every readable folder'],
                    ],
                    'required' => ['pattern', 'path'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return in_array($tool, ['list_files', 'read_file', 'search_code'], true);
    }

    /** Runs a tool and returns its text result, secrets hidden; a RuntimeException means the input was refused */
    public function run(string $tool, array $input): string
    {
        return $this->redactor->redact(match ($tool) {
            'list_files' => $this->listFiles((string) $input['path']),
            'read_file' => $this->readFile((string) $input['path'], (int) ($input['from_line'] ?? 1)),
            'search_code' => $this->search((string) $input['pattern'], ($input['path'] ?? '') !== '' ? $input['path'] : null),
            default => throw new RuntimeException("Unknown tool $tool"),
        });
    }

    /** How many secrets the last tool calls hid */
    public function hiddenSecrets(): int
    {
        return $this->redactor->takeCount();
    }

    /** The app's routes, one per line, sent with the first request */
    public function routeList(): string
    {
        return collect(Router::getRoutes()->getRoutes())
            ->reject(fn (Route $r) => Str::startsWith($r->getName() ?? '', ['video-demo.', 'ignition.', 'debugbar.', 'telescope', 'horizon', 'sanctum.'])
                || Str::startsWith($r->uri(), ['_', 'telescope', 'horizon']))
            ->map(fn (Route $r) => sprintf(
                '%s /%s%s%s%s',
                implode('|', array_diff($r->methods(), ['HEAD'])),
                ltrim($r->uri(), '/'),
                $r->getName() ? "  name={$r->getName()}" : '',
                $r->getActionName() !== 'Closure' ? '  action='.Str::after($r->getActionName(), 'App\\Http\\Controllers\\') : '',
                ($mw = array_values(array_intersect($r->gatherMiddleware(), ['auth', 'guest', 'verified', 'admin']))) ? '  middleware='.implode(',', $mw) : '',
            ))
            ->values()
            ->implode("\n");
    }

    public function routeCount(): int
    {
        return count(Router::getRoutes()->getRoutes());
    }

    protected function listFiles(string $path): string
    {
        $dir = $this->resolve($path, mustBeDir: true);

        $files = [];
        foreach (Finder::create()->files()->in($dir)->sortByName() as $file) {
            $relative = $this->relative($file->getPathname());
            if (! $this->denied($relative)) {
                $files[] = $relative;
            }
            if (count($files) >= self::MAX_LISTED) {
                $files[] = '… more files not shown; list a smaller folder';
                break;
            }
        }

        return $files ? implode("\n", $files) : 'No files.';
    }

    protected function readFile(string $path, int $fromLine): string
    {
        $file = $this->resolve($path);
        $max = (int) config('video-demo.ai.code.max_file_bytes', 60_000);

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $out = '';
        $from = max(1, $fromLine);

        for ($n = $from; $n <= count($lines); $n++) {
            $line = $n.'  '.$lines[$n - 1]."\n";
            if (strlen($out) + strlen($line) > $max) {
                return $out.'… cut at line '.($n - 1).' of '.count($lines).'. Read on with from_line '.$n.'.';
            }
            $out .= $line;
        }

        return $out !== '' ? $out : 'The file is empty or shorter than from_line.';
    }

    protected function search(string $pattern, ?string $path): string
    {
        $dirs = $path ? [$this->resolve($path, mustBeDir: true)] : array_filter(
            array_map(fn ($p) => $this->root.'/'.$p, $this->allowedPaths()),
            'is_dir',
        );
        $regex = '~'.str_replace('~', '\~', $pattern).'~i';

        if (@preg_match($regex, '') === false) {
            throw new RuntimeException('That is not a valid regular expression.');
        }

        $matches = [];
        foreach (Finder::create()->files()->in($dirs)->size('< 1M') as $file) {
            $relative = $this->relative($file->getPathname());
            if ($this->denied($relative)) {
                continue;
            }
            foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
                if (preg_match($regex, $line)) {
                    $matches[] = "$relative:".($i + 1).': '.Str::limit(trim($line), 200);
                    if (count($matches) >= self::MAX_MATCHES) {
                        return implode("\n", $matches)."\n… more matches not shown; make the pattern more specific";
                    }
                }
            }
        }

        return $matches ? implode("\n", $matches) : 'No matches.';
    }

    /** Turns a path from Claude into a real path inside an allowed folder, or refuses it */
    protected function resolve(string $path, bool $mustBeDir = false): string
    {
        $real = realpath($this->root.'/'.ltrim($path, '/'));

        if ($real === false) {
            throw new RuntimeException("$path does not exist.");
        }

        $relative = $this->relative($real);
        $allowed = collect($this->allowedPaths())->contains(
            fn ($p) => $relative === $p || str_starts_with($relative, $p.'/')
        );

        if (! str_starts_with($real, $this->root.'/') || ! $allowed) {
            throw new RuntimeException("$path is outside the folders you can read: ".implode(', ', $this->allowedPaths()).'.');
        }
        if ($this->denied($relative)) {
            throw new RuntimeException("$path may contain secrets and cannot be read.");
        }
        if ($mustBeDir !== is_dir($real)) {
            throw new RuntimeException($mustBeDir ? "$path is a file, not a folder." : "$path is a folder; use list_files.");
        }

        return $real;
    }

    protected function denied(string $relative): bool
    {
        $name = basename($relative);

        foreach (config('video-demo.ai.code.deny', []) as $pattern) {
            if (fnmatch($pattern, $name, FNM_CASEFOLD) || fnmatch($pattern, $relative, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    protected function relative(string $absolute): string
    {
        return ltrim(Str::after($absolute, $this->root), '/');
    }

    /** @return list<string> */
    protected function allowedPaths(): array
    {
        return array_values(array_map(fn ($p) => trim($p, '/'), config('video-demo.ai.code.paths', [])));
    }
}
