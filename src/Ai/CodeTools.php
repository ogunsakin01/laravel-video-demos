<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * The tools the model uses to read the app while planning. Reading is limited to the folders
 * in video-demo.ai.code.paths, and files matching video-demo.ai.code.deny (such as .env) are never returned.
 */
class CodeTools
{
    protected const MAX_LISTED = 400;

    protected const MAX_MATCHES = 60;

    protected Redactor $redactor;

    /**
     * The files read since the last takeReadFiles(), relative to the app's root.
     *
     * @var array<string, true>
     */
    protected array $read = [];

    /**
     * Create a new code tools instance.
     */
    public function __construct(protected ?string $root = null, ?Redactor $redactor = null)
    {
        $this->redactor = $redactor ?? new Redactor;
        $root ??= base_path();
        $this->root = rtrim(realpath($root) ?: $root, '/');
    }

    /**
     * Get the tool definitions: a name, description and JSON schema for each tool's input.
     */
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

    /**
     * Determine if the given tool is one of these code tools.
     */
    public function handles(string $tool): bool
    {
        return in_array($tool, ['list_files', 'read_file', 'search_code'], true);
    }

    /**
     * Run a tool and return its text result, with secrets hidden.
     *
     * @throws RuntimeException When the input is refused.
     */
    public function run(string $tool, array $input): string
    {
        return $this->redactor->redact(match ($tool) {
            'list_files' => $this->listFiles($this->required($input, 'path')),
            'read_file' => $this->readFile($this->required($input, 'path'), (int) ($input['from_line'] ?? 1)),
            'search_code' => $this->search($this->required($input, 'pattern'), ($input['path'] ?? '') !== '' ? (string) $input['path'] : null),
            default => throw new RuntimeException("Unknown tool $tool"),
        });
    }

    /**
     * Get a tool input the model must give, refusing the call when it's missing.
     *
     * @throws RuntimeException
     */
    protected function required(array $input, string $key): string
    {
        if (! isset($input[$key]) || ! is_scalar($input[$key])) {
            throw new RuntimeException("$key is required.");
        }

        return (string) $input[$key];
    }

    /**
     * Get the files read since the last call, relative to the app's root, so
     * a video knows the code it was planned from.
     *
     * @return list<string>
     */
    public function takeReadFiles(): array
    {
        $files = array_keys($this->read);
        $this->read = [];

        return $files;
    }

    /**
     * Get how many secrets the tool calls hid since the last check.
     */
    public function hiddenSecrets(): int
    {
        return $this->redactor->takeCount();
    }

    /**
     * Get the app's routes, one per line, to send with the first request.
     *
     * Each route lists the sign-in middleware it uses, or with $allMiddleware
     * every middleware, such as permission:view reports.
     */
    public function routeList(bool $allMiddleware = false): string
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
                ($mw = $this->middlewareOf($r, $allMiddleware)) ? '  middleware='.implode(',', $mw) : '',
            ))
            ->values()
            ->implode("\n");
    }

    /**
     * Get the middleware of a route worth listing. Middleware added as a
     * closure, as a controller can, has no name and is left out.
     *
     * @return list<string>
     */
    protected function middlewareOf(Route $route, bool $all): array
    {
        $names = array_filter($route->gatherMiddleware(), is_string(...));

        return array_values($all
            ? array_unique(array_diff($names, ['web', 'api']))
            : array_intersect($names, ['auth', 'guest', 'verified', 'admin']));
    }

    /**
     * Get the number of routes the app registers.
     */
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
        $this->read[$this->relative($file)] = true;

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
            array_map(fn ($p) => implode('/', array_filter($this->mount($p), fn ($part) => $part !== '')), $this->allowedPaths()),
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

    /**
     * Resolve a path from the model to a real path inside an allowed folder, or refuse it.
     */
    protected function resolve(string $path, bool $mustBeDir = false): string
    {
        [$base, $inner] = $this->mount(ltrim($path, '/'));
        $real = realpath($base.'/'.$inner);

        if ($real === false) {
            throw new RuntimeException("$path does not exist.");
        }

        $relative = $this->relative($real);
        $allowed = collect($this->allowedPaths())->contains(
            fn ($p) => $relative === $p || str_starts_with($relative, $p.'/')
        );

        if (! str_starts_with($real, $base.'/') || ! $allowed) {
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

    /**
     * Determine if the path matches the deny list, by file name or relative path.
     */
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
        $frontend = $this->frontendRoot();
        if ($frontend !== null && str_starts_with($absolute, $frontend.'/')) {
            return 'frontend/'.ltrim(Str::after($absolute, $frontend), '/');
        }

        return ltrim(Str::after($absolute, $this->root), '/');
    }

    /**
     * Get the folders the model may read, relative to the app's root, and a
     * separate frontend's under frontend/.
     *
     * @return list<string>
     */
    protected function allowedPaths(): array
    {
        $paths = array_map(fn ($p) => trim($p, '/'), config('video-demo.ai.code.paths', []));
        if ($this->frontendRoot() !== null) {
            foreach ((array) config('video-demo.frontend.code', ['src']) as $p) {
                $paths[] = 'frontend/'.trim((string) $p, '/');
            }
        }

        return array_values($paths);
    }

    /**
     * Get the folder a path the model gives lives in, and the rest of it: a
     * separate frontend's repository for frontend/…, the app's otherwise.
     *
     * @return array{0: string, 1: string}
     */
    protected function mount(string $path): array
    {
        $frontend = $this->frontendRoot();
        if ($frontend !== null && ($path === 'frontend' || str_starts_with($path, 'frontend/'))) {
            return [$frontend, ltrim(substr($path, strlen('frontend')), '/')];
        }

        return [$this->root, $path];
    }

    /**
     * Get the separate frontend's repository (video-demo.frontend.path, from
     * the app's root when relative), or null when there is none.
     */
    protected function frontendRoot(): ?string
    {
        $path = (string) config('video-demo.frontend.path');
        if ($path === '') {
            return null;
        }
        $real = realpath(str_starts_with($path, '/') ? $path : $this->root.'/'.$path);

        return $real === false ? null : rtrim($real, '/');
    }
}
