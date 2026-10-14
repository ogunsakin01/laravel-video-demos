<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Ogunsakin01\VideoDemo\Recording\RecordAs;

/**
 * Signs the recorder's browser in as the user a video is recorded as. The
 * link is signed and expires after ten minutes, so only the package can make
 * one, and it only exists while the studio is enabled.
 */
class RecorderLoginController extends Controller
{
    public function __invoke(string $user): RedirectResponse
    {
        try {
            $account = RecordAs::find($user);
        } catch (\RuntimeException) {
            abort(404);
        }

        Auth::guard(config('video-demo.recorder.guard'))->login($account);
        request()->session()->regenerate();

        return redirect('/');
    }
}
