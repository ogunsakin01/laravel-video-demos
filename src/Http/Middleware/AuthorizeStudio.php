<?php

namespace Ogunsakin01\VideoDemo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may use the studio, like Horizon and Telescope: anyone on a local
 * machine (or in tests), and elsewhere only users the app's gate allows.
 * Without a gate, a studio enabled outside local (on a staging server, say)
 * is closed, so nobody can spend the app's API credit or have Claude read
 * its code.
 *
 *   Gate::define('viewVideoDemoStudio', fn ($user) => $user->isAdmin());
 */
class AuthorizeStudio
{
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
