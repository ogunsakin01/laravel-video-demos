<?php

namespace Ogunsakin01\VideoDemo\Studio;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Recording\Recorder;

/**
 * The pages a video can start on, for the new-video form: the app's GET
 * routes that need no parameters, checked in a browser as the chosen user
 * so only real HTML pages are offered, each with its heading or title.
 */
class Pages
{
    /** Routes that are never pages, or that change something when opened */
    protected const SKIP_URIS = ['_', 'api/', 'sanctum/', 'telescope', 'horizon', 'livewire', 'broadcasting/', 'storage/', 'up', 'video-demo'];

    protected const SKIP_WORDS = '/(^|[\s\/._-])(logout|log-out|signout|sign-out|delete|destroy|remove|export|download|impersonate|webhooks?|callback|unsubscribe)([\s\/._-]|$)/i';

    public function __construct(protected Recorder $recorder) {}

    /**
     * Paths worth checking, with their route names
     *
     * @return array<string, ?string> path => route name
     */
    public function candidates(): array
    {
        $paths = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = ltrim($route->uri(), '/');
            $name = $route->getName();

            if (! in_array('GET', $route->methods(), true)
                || Str::startsWith($uri, self::SKIP_URIS)
                || Str::startsWith($name ?? '', ['video-demo.studio.', 'ignition.', 'debugbar.', 'sanctum.', 'livewire.', 'telescope', 'horizon'])
                || preg_match(self::SKIP_WORDS, $uri.' '.$name)
                || preg_match('/\.(json|xml|txt|csv|pdf|js|css|map|ico|png|jpe?g|svg|webp)$/i', $uri)
                || in_array('signed', $route->gatherMiddleware(), true)
                || in_array('api', $route->gatherMiddleware(), true)) {
                continue;
            }

            // Optional parameters are left off; required ones can't be guessed
            $path = '/'.trim(preg_replace('/\{[^}]+\?\}/', '', $uri), '/');
            if (str_contains($path, '{') || str_contains($path, '//')) {
                continue;
            }

            $paths[$path] ??= $name;
        }
        ksort($paths);

        return $paths;
    }

    /**
     * The pages this user can open. Kept for a while, as checking takes a
     * few seconds; $fresh checks again.
     *
     * @return array{pages: list<array{path: string, title: string, name: ?string}>, skipped: int, checked_at: string}
     */
    public function for(?string $recordAs, bool $fresh = false): array
    {
        $candidates = $this->candidates();
        $key = 'video-demo.pages.'.md5(($recordAs ?? '').'|'.implode(',', array_keys($candidates)));

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(30), function () use ($candidates, $recordAs) {
            $scan = $candidates === [] ? ['pages' => [], 'skipped' => []] : $this->recorder->scanPages(array_keys($candidates), $recordAs);

            $pages = collect($scan['pages'])
                ->map(fn ($p) => ['path' => $p['path'], 'title' => $p['title'] ?: Str::headline($candidates[$p['path']] ?? ''), 'name' => $candidates[$p['path']] ?? null])
                ->sortBy('path')
                ->values()
                ->all();

            return ['pages' => $pages, 'skipped' => count($scan['skipped']), 'checked_at' => now()->toIso8601String()];
        });
    }
}
