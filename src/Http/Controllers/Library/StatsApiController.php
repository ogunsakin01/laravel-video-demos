<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Library;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ogunsakin01\VideoDemo\Library\Stats;

/**
 * Lists how the videos are watched, for the studio on a developer's machine.
 */
class StatsApiController extends Controller
{
    /**
     * Display the counts of the last days (90 unless ?days= says), as JSON.
     */
    public function __invoke(Request $request, Stats $stats): JsonResponse
    {
        $token = (string) config('video-demo.library.requests_token');

        abort_unless($token !== '' && hash_equals($token, (string) $request->bearerToken()), 401);

        return response()->json([...$stats->summary(max(1, min(365, (int) $request->query('days', 90)))), 'enabled' => Stats::enabled()]);
    }
}
