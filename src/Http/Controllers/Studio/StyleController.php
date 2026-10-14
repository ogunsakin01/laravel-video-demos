<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Ogunsakin01\VideoDemo\Models\VideoStyle;

/**
 * Manages the video styles on the studio's Styles page.
 */
class StyleController extends Controller
{
    /**
     * Display the styles, and the one being edited.
     */
    public function index(Request $request): View
    {
        $styles = VideoStyle::options();

        return view('video-demo::studio.styles', [
            'styles' => $styles,
            'editing' => $styles->firstWhere('id', (int) $request->query('edit')),
        ]);
    }

    /**
     * Store a new style.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // Copy the defaults first, so adding a style never hides them.
        VideoStyle::options();
        VideoStyle::create($data);

        return redirect()->route('video-demo.studio.styles.index')->with('status', 'Style added.');
    }

    /**
     * Update the given style.
     */
    public function update(Request $request, VideoStyle $style): RedirectResponse
    {
        $style->update($this->validated($request));

        return redirect()->route('video-demo.studio.styles.index')->with('status', 'Style saved. Videos already made keep the style they were made with.');
    }

    /**
     * Remove the given style.
     */
    public function destroy(VideoStyle $style): RedirectResponse
    {
        if (VideoStyle::count() <= 1) {
            return back()->with('error', 'Keep at least one style.');
        }

        $style->delete();

        return redirect()->route('video-demo.studio.styles.index')->with('status', 'Style deleted.');
    }

    /**
     * Validate the style form.
     */
    protected function validated(Request $request): array
    {
        return [...$request->validate([
            'name' => ['required', 'string', 'max:80'],
            'direction' => ['required', 'string', 'max:2000'],
            'theme' => ['required', Rule::in(array_keys(VideoStyle::THEMES))],
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'captions' => ['required', Rule::in(array_keys(VideoStyle::CAPTIONS))],
            'pace' => ['required', Rule::in(array_keys(VideoStyle::PACES))],
            'art' => ['required', Rule::in(array_keys(VideoStyle::ARTS))],
            'app_theme' => ['nullable', Rule::in(array_keys(VideoStyle::APP_THEMES))],
        ]), 'app_theme' => $request->input('app_theme') ?: 'auto', 'zoom' => $request->boolean('zoom'), 'highlight' => $request->boolean('highlight')];
    }
}
