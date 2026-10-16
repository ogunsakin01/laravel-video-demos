<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Voice\Music;
use Ogunsakin01\VideoDemo\Voice\Voices;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Manages the video styles on the studio's Styles page.
 */
class StyleController extends Controller
{
    /**
     * The image types a logo may be.
     */
    protected const LOGO_TYPES = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

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
        $style->update($this->validated($request, $style));

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

        // Its logo file stays: videos made with it may be recorded again.
        $style->delete();

        return redirect()->route('video-demo.studio.styles.index')->with('status', 'Style deleted.');
    }

    /**
     * Show a style's logo.
     */
    public function logo(VideoStyle $style): BinaryFileResponse
    {
        $disk = Storage::disk(config('video-demo.storage.work_disk'));
        abort_unless($style->logo && $disk->exists($style->logo), 404);

        // An SVG can carry script: it is shown as an image only, never run.
        return response()->file($disk->path($style->logo), [
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Validate the style form, keeping an uploaded logo.
     */
    protected function validated(Request $request, ?VideoStyle $style = null): array
    {
        $request->validate([
            'logo' => ['nullable', 'file', 'mimes:'.implode(',', self::LOGO_TYPES), 'max:'.VideoStyle::LOGO_MAX_KB],
            'logo_position' => ['nullable', Rule::in(array_keys(VideoStyle::LOGO_POSITIONS))],
            'end_title' => ['nullable', 'string', 'max:120'],
            'end_text' => ['nullable', 'string', 'max:200'],
        ]);

        // A new file replaces the logo; "remove" takes it away. The old file is
        // kept, since videos made with it may be recorded again.
        $logo = $style?->logo;
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            // The extension comes from the file's content, not its name, so it is always an image's.
            $extension = in_array($file->extension(), self::LOGO_TYPES, true) ? $file->extension() : 'png';
            $logo = 'video-demo/logos/'.Str::lower(Str::random(16)).'.'.$extension;
            Storage::disk(config('video-demo.storage.work_disk'))->put($logo, $file->get());
        } elseif ($request->boolean('remove_logo')) {
            $logo = null;
        }

        return [...$this->settings($request), 'logo' => $logo, 'logo_position' => $logo ? ($request->input('logo_position') ?: 'top-right') : 'none',
            'end_title' => trim((string) $request->input('end_title')) ?: null, 'end_text' => trim((string) $request->input('end_text')) ?: null];
    }

    /**
     * Validate the style's other settings.
     */
    protected function settings(Request $request): array
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
            'voice' => ['nullable', 'string', Rule::in([...array_keys(Voices::all()), ...array_filter([$request->route('style')?->voice])])],
            'music' => ['nullable', 'string', Rule::in([...array_keys(Music::options()), ...array_filter([$request->route('style')?->music])])],
        ]), 'app_theme' => $request->input('app_theme') ?: 'auto', 'zoom' => $request->boolean('zoom'), 'highlight' => $request->boolean('highlight'),
            'voice' => $request->input('voice') ?: null, 'music' => $request->input('music') ?: null];
    }
}
