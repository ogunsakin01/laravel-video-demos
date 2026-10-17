<?php

namespace Ogunsakin01\VideoDemo\Studio;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Recording\Recorder;

/**
 * Finds the pages a video can start on, for the new-video form. The app's GET
 * routes without required parameters are opened in a browser as the chosen
 * user, so only real HTML pages are offered, each with its heading or title.
 */
class Pages
{
    /**
     * The first URI segment of routes that are never pages, or that change
     * something when opened. Any route starting with "_" is skipped too.
     */
    protected const SKIP_SEGMENTS = ['api', 'sanctum', 'telescope', 'horizon', 'livewire', 'broadcasting', 'storage', 'up', 'video-demo'];

    /**
     * Words in a route's URI or name that mark it as unsafe to open or not a page.
     */
    protected const SKIP_WORDS = '/(^|[\s\/._-])(logout|log-out|signout|sign-out|delete|destroy|remove|export|download|impersonate|webhooks?|callback|unsubscribe)([\s\/._-]|$)/i';

    /**
     * Create a new page finder instance.
     */
    public function __construct(protected Recorder $recorder) {}

    /**
     * Get the paths worth checking, with their route names.
     *
     * @return array<string, ?string> path => route name
     */
    public function candidates(): array
    {
        // A separate frontend's pages aren't Laravel routes, which are its API.
        if (config('video-demo.frontend.url')) {
            return [];
        }

        $paths = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = ltrim($route->uri(), '/');
            $name = $route->getName();

            if (! in_array('GET', $route->methods(), true)
                || str_starts_with($uri, '_')
                || in_array(Str::before($uri, '/'), self::SKIP_SEGMENTS, true)
                || Str::startsWith($name ?? '', ['video-demo.studio.', 'ignition.', 'debugbar.', 'sanctum.', 'livewire.', 'telescope', 'horizon'])
                || preg_match(self::SKIP_WORDS, $uri.' '.$name)
                || preg_match('/\.(json|xml|txt|csv|pdf|js|css|map|ico|png|jpe?g|svg|webp)$/i', $uri)
                || in_array('signed', $route->gatherMiddleware(), true)
                || in_array('api', $route->gatherMiddleware(), true)) {
                continue;
            }

            // Optional parameters are left off; required ones can't be guessed.
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
     * Get the pages the given user can open.
     *
     * The result is cached for 30 minutes, as checking takes a few seconds.
     * Pass $fresh to check again.
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
