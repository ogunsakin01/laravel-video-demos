<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Library;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Ogunsakin01\VideoDemo\Library\Manifest;
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

        return view('video-demo::library.index', [
            'area' => $area,
            'query' => $query,
            'videos' => $query !== '' ? $this->manifest->search($query, $area) : $this->manifest->forArea($area),
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

        return view('video-demo::library.show', [
            'area' => $area,
            'video' => $video,
            'more' => collect($this->manifest->forArea($area))->where('slug', '!=', $slug)->take(6)->values(),
        ]);
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
