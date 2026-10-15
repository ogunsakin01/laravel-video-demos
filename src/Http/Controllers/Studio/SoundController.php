<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ogunsakin01\VideoDemo\Voice\Music;
use Ogunsakin01\VideoDemo\Voice\Speaker;
use Ogunsakin01\VideoDemo\Voice\Voices;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Voice samples, and the background music tracks: playing, uploading and
 * deleting them.
 */
class SoundController extends Controller
{
    /**
     * The line every voice sample speaks.
     */
    public const SAMPLE = 'Here is how it works: open the page, fill in the details, and save.';

    /**
     * Speak a short sample in the given voice. It is saved like any line,
     * so playing it again is instant, and free for a voice that costs.
     */
    public function sample(Request $request, Speaker $speaker): BinaryFileResponse|JsonResponse
    {
        $voice = (string) $request->query('voice', '');
        if (! Voices::available($voice)) {
            return response()->json(['error' => 'That voice is not available here.'], 404);
        }

        try {
            // As the voice makes it: no ffmpeg needed, so samples work in any web request.
            $clip = $speaker->speak($voice, [self::SAMPLE], mixable: false)[self::SAMPLE];
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->file($clip['file'], ['Content-Type' => str_ends_with($clip['file'], '.mp3') ? 'audio/mpeg' : 'audio/wav', 'Cache-Control' => 'no-store']);
    }

    /**
     * Play a music track.
     */
    public function play(string $key): BinaryFileResponse|JsonResponse
    {
        abort_unless(Music::find($key) !== null, 404);

        try {
            // A built-in track is downloaded the first time it is played.
            return response()->file(Music::local($key), ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * Upload a music track.
     */
    public function upload(Request $request): RedirectResponse
    {
        $request->validate(['track' => ['required', 'file', 'max:51200']]);

        try {
            $key = Music::upload($request->file('track'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['track' => $e->getMessage()]);
        }

        return redirect(route('video-demo.studio.styles.index').'#music')->with('status', 'Added "'.Music::label($key).'". Choose it on a video, or as a style\'s default.');
    }

    /**
     * Delete an uploaded music track.
     */
    public function destroy(string $key): RedirectResponse
    {
        Music::delete($key);

        return redirect(route('video-demo.studio.styles.index').'#music')->with('status', 'Track deleted. Videos already made keep their sound.');
    }
}
