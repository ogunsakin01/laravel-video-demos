<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Ogunsakin01\VideoDemo\Recording\RecordAs;
use Ogunsakin01\VideoDemo\Recording\SignIn;
use RuntimeException;

/**
 * Signs the recorder's browser in as the user a video is recorded as. The
 * link is signed and expires after ten minutes, so only the package can make
 * one, and the route only exists while the studio is enabled.
 */
class RecorderLoginController extends Controller
{
    /**
     * Sign the recorder in as the given user.
     */
    public function __invoke(string $user): RedirectResponse
    {
        try {
            $account = RecordAs::find($user);
        } catch (RuntimeException) {
            abort(404);
        }

        Auth::guard(config('video-demo.recorder.guard'))->login($account);
        request()->session()->regenerate();

        return redirect('/');
    }

    /**
     * Get what the recorder's browser needs to be signed in to a separate
     * frontend as the given user: a token, cookies or headers. On sample
     * data this runs in the video's own database, so a token is made there.
     */
    public function signIn(string $user): JsonResponse
    {
        try {
            $account = RecordAs::find($user);
        } catch (RuntimeException) {
            abort(404);
        }

        try {
            $signIn = SignIn::for($account)->toArray();
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json($signIn)->header('Cache-Control', 'no-store');
    }
}
