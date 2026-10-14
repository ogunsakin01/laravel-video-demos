<?php

namespace Ogunsakin01\VideoDemo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides who may use the studio, as Horizon and Telescope do. Anyone may on
 * a local machine or in tests; elsewhere, only users the app's gate allows.
 *
 * Without the gate, a studio enabled outside local (on a staging server, say)
 * is closed, so nobody can spend the app's API credit or have the AI model
 * read its code:
 *
 *     Gate::define('viewVideoDemoStudio', fn ($user) => $user->isAdmin());
 */
class AuthorizeStudio
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $gate = config('video-demo.studio.gate', 'viewVideoDemoStudio');

        $allowed = Gate::has($gate)
            ? Gate::forUser($request->user())->check($gate)
            : app()->environment('local', 'testing');

        abort_unless($allowed, 403);

        return $next($request);
    }
}
