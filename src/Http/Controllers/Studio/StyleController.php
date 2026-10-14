<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Ogunsakin01\VideoDemo\Models\VideoStyle;

class StyleController extends Controller
{
    public function index(Request $request): View
    {
        $styles = VideoStyle::options();

        return view('video-demo::studio.styles', [
            'styles' => $styles,
            'editing' => $styles->firstWhere('id', (int) $request->query('edit')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // Copy the defaults first, so adding a style never hides them
        VideoStyle::options();
        VideoStyle::create($data);

        return redirect()->route('video-demo.studio.styles.index')->with('status', 'Style added.');
    }

    public function update(Request $request, VideoStyle $style): RedirectResponse
    {
        $style->update($this->validated($request));

        return redirect()->route('video-demo.studio.styles.index')->with('status', 'Style saved. Videos already made keep the style they were made with.');
    }

    public function destroy(VideoStyle $style): RedirectResponse
    {
        if (VideoStyle::count() <= 1) {
            return back()->with('error', 'Keep at least one style.');
        }

        $style->delete();

        return redirect()->route('video-demo.studio.styles.index')->with('status', 'Style deleted.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'direction' => ['required', 'string', 'max:2000'],
            'theme' => ['required', Rule::in(array_keys(VideoStyle::THEMES))],
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'captions' => ['required', Rule::in(array_keys(VideoStyle::CAPTIONS))],
            'pace' => ['required', Rule::in(array_keys(VideoStyle::PACES))],
            'art' => ['required', Rule::in(array_keys(VideoStyle::ARTS))],
        ]);
    }
}
