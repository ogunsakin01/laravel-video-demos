<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Library;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Models\VideoRequest;

class LibraryController extends Controller
{
    public function __construct(protected Manifest $manifest) {}

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

    public function show(Request $request, string $slug): View
    {
        $area = $this->area($request);
        $video = $this->manifest->find($slug, $area);

        abort_unless($video, 404);

        return view('video-demo::library.show', [
            'area' => $area,
            'video' => $video,
            'more' => collect($this->manifest->forArea($area))->where('slug', '!=', $slug)->take(6)->values(),
        ]);
    }

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

    protected function area(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'video-demo.private.') ? 'private' : 'public';
    }
}
