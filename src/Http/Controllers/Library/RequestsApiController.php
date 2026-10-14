<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Library;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ogunsakin01\VideoDemo\Models\VideoRequest;

/** Open video requests, for the studio on a developer's machine */
class RequestsApiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('video-demo.library.requests_token');

        abort_unless($token !== '' && hash_equals($token, (string) $request->bearerToken()), 401);

        return response()->json([
            'requests' => VideoRequest::where('status', 'open')->latest()->limit(100)->get(['id', 'question', 'page_url', 'created_at']),
        ]);
    }
}
