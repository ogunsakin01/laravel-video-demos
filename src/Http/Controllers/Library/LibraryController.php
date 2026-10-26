<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Library;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\PlayerScripts;
use Ogunsakin01\VideoDemo\Library\Stats;
use Ogunsakin01\VideoDemo\Models\VideoRequest;
use SimpleXMLElement;

/**
 * Shows the public and private video libraries to the app's users.
 */
class LibraryController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(protected Manifest $manifest) {}

    /**
     * Display the library, or the videos matching a search.
     */
    public function index(Request $request): View
    {
        $area = $this->area($request);
        $query = trim((string) $request->query('q', ''));

        $videos = $query !== '' ? $this->manifest->search($query, $area) : $this->manifest->forArea($area);
        if ($query !== '' && $videos === [] && Stats::enabled()) {
            app(Stats::class)->searchMiss($query);
        }

        return view('video-demo::library.index', [
            'area' => $area,
            'query' => $query,
            // Each in the viewer's language, where there is a version in it.
            'videos' => array_map(fn ($v) => $this->manifest->localized($v, app()->getLocale(), $area), $videos),
            'requests' => $area === 'private'
                ? VideoRequest::where('status', 'open')->latest()->limit(50)->get()
                : collect(),
        ]);
    }

    /**
     * Get a published video as JSON, for the <video-demo-player> web
     * component in frontends that aren't Blade: its title, chapters and each
     * version's files, with full addresses. ?lang= picks the version in that
     * language when there is one. Public videos may be fetched from any
     * address; private ones only by signed-in staff on this one.
     */
    public function json(Request $request, string $slug): JsonResponse
    {
        $area = $this->area($request);
        $video = $this->manifest->find($slug, $area);
        abort_if($video === null, 404);
        if (is_string($language = $request->query('lang'))) {
            $video = $this->manifest->localized($video, $language, $area);
        }

        $absolute = fn (?string $u) => $u !== null && str_starts_with($u, '/') ? url($u) : $u;
        $sources = [];
        foreach (Manifest::sources($video) as $device => $source) {
            $sources[$device] = [
                'video' => $absolute($this->manifest->url($video, 'video', $device)),
                'poster' => $absolute($this->manifest->url($video, 'poster', $device)),
                'subtitles' => $absolute($this->manifest->url($video, 'subtitles', $device)),
                'width' => $source['width'],
                'height' => $source['height'],
            ];
        }

        return response()->json([
            'slug' => $video['slug'],
            'title' => $video['title'],
            'summary' => $video['summary'] ?? '',
            'language' => $video['language'] ?? null,
            'duration' => $video['duration'] ?? null,
            'chapters' => $video['chapters'] ?? [],
            'sources' => $sources,
            'page' => route("video-demo.$area.show", $video['slug']),
            // Where a player counts plays and how far viewers get, when the app turns that on.
            'stats' => Stats::enabled() ? route("video-demo.$area.events", $video['slug']) : null,
            // How the player behaves, as the app sets it; the element's attributes can say otherwise.
            'player' => ['pause_when_away' => (bool) config('video-demo.library.player.pause_when_away')],
        ])->withHeaders($area === 'public'
            ? ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=300']
            : ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Get the public videos that show a page of a frontend that isn't Blade,
     * for <video-demo-help>: ?path=/jobs/new, in the page's language with ?lang=.
     */
    public function forPage(Request $request): JsonResponse
    {
        abort_if($this->area($request) === 'private', 404);
        $path = (string) $request->query('path', '/');
        $language = $request->query('lang');

        $videos = array_map(function (array $video) use ($language) {
            $video = is_string($language) ? $this->manifest->localized($video, $language, 'public') : $video;
            $poster = $this->manifest->url($video, 'poster');

            return [
                'slug' => $video['slug'],
                'title' => $video['title'],
                'duration' => $video['duration'] ?? null,
                'poster' => $poster !== null && str_starts_with($poster, '/') ? url($poster) : $poster,
                'page' => route('video-demo.public.show', $video['slug']),
            ];
        }, $this->manifest->forRoute(null, (string) parse_url($path, PHP_URL_PATH), 'public'));

        return response()->json(['videos' => $videos])->withHeaders(['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=300']);
    }

    /**
     * Get the script that defines <video-demo-player>, for any frontend.
     */
    public function embedScript(): Response
    {
        return response(PlayerScripts::embed(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Get a sitemap of the public videos, with each video's poster, file and
     * length, for search engines. The private library has none.
     */
    public function sitemap(Request $request): Response
    {
        abort_if($this->area($request) === 'private', 404);

        $absolute = fn (?string $u) => $u !== null && str_starts_with($u, '/') ? url($u) : $u;
        $xml = new SimpleXMLElement('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"/>');
        foreach ($this->manifest->visible('public') as $video) {
            $url = $xml->addChild('url');
            $url->addChild('loc', htmlspecialchars(route('video-demo.public.show', $video['slug'])));
            $entry = $url->addChild('video', null, 'http://www.google.com/schemas/sitemap-video/1.1');
            foreach ([
                'thumbnail_loc' => $absolute($this->manifest->url($video, 'poster')),
                'title' => $video['title'],
                'description' => trim((string) ($video['summary'] ?? '')) ?: $video['title'],
                'content_loc' => $absolute($this->manifest->url($video)),
                'duration' => (int) round($video['duration'] ?? 0) ?: null,
                'publication_date' => $video['published_at'] ?? null,
            ] as $name => $value) {
                if ($value !== null && $value !== '') {
                    $entry->addChild($name, htmlspecialchars((string) $value), 'http://www.google.com/schemas/sitemap-video/1.1');
                }
            }
        }

        return response((string) $xml->asXML(), 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Display the given video.
     */
    public function show(Request $request, string $slug): View
    {
        $area = $this->area($request);
        $video = $this->manifest->find($slug, $area);

        abort_if($video === null, 404);

        // An original opens in the viewer's language, or the one picked from its list of languages.
        $language = $request->query('lang');
        if (is_string($language) || empty($video['translation_of'])) {
            $video = $this->manifest->localized($video, is_string($language) ? $language : app()->getLocale(), $area);
        }
        $original = ! empty($video['translation_of']) ? ($this->manifest->find($video['translation_of'], $area) ?? $video) : $video;
        $versions = array_values(array_filter([$original, ...$this->manifest->translations($original['slug'], $area)], fn ($v) => ! empty($v['language'])));

        return view('video-demo::library.show', [
            'area' => $area,
            'video' => $video,
            'languages' => count($versions) > 1 ? $versions : [],
            'more' => collect($this->manifest->forArea($area))->where('slug', '!=', $original['slug'])->take(6)->values(),
        ]);
    }

    /**
     * Count a play, or a quarter of a video reached, sent by the player.
     */
    public function event(Request $request, string $slug, Stats $stats): Response
    {
        abort_unless(Stats::enabled() && $this->manifest->find($slug, $this->area($request)), 404);
        $event = $request->input('event');
        abort_unless(is_string($event) && in_array($event, Stats::EVENTS, true), 422);

        $stats->record($slug, $event);

        return response()->noContent();
    }

    /**
     * Store a user's request for a video.
     */
    public function request(Request $request): RedirectResponse
    {
        abort_unless(config('video-demo.library.accept_requests'), 404);

        $data = $request->validate([
            'question' => ['required', 'string', 'min:5', 'max:500'],
            'page_url' => ['nullable', 'string', 'max:255'],
        ]);

        VideoRequest::create([
            'question' => Str::squish($data['question']),
            'page_url' => $data['page_url'] ?? null,
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        return back()->with('video-demo.requested', true);
    }

    /**
     * Get the library area the current route belongs to.
     */
    protected function area(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'video-demo.private.') ? 'private' : 'public';
    }
}
