<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Library;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\Stats;
use Ogunsakin01\VideoDemo\Models\VideoRequest;

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
