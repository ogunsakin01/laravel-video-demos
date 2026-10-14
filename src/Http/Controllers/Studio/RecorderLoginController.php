<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Ogunsakin01\VideoDemo\Recording\Recorder;

/**
 * Signs the recorder's browser in as the configured demo user. The link is
 * signed, expires after ten minutes, only accepts that one user, and only
 * exists while the studio is enabled.
 */
class RecorderLoginController extends Controller
{
    public function __invoke(string $user): RedirectResponse
    {
        $demoUser = Recorder::loginUser();

        abort_unless($demoUser && (string) $demoUser->getAuthIdentifier() === $user, 403);

        Auth::guard(config('video-demo.recorder.guard'))->login($demoUser);
        request()->session()->regenerate();

        return redirect('/');
    }
}
