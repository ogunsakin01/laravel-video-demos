<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\CostEstimator;
use Ogunsakin01\VideoDemo\Ai\FormAssistant;
use Ogunsakin01\VideoDemo\Ai\PlanningFailed;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoRequest;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class VideoController extends Controller
{
    protected const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function index(): View
    {
        return view('video-demo::studio.index', [
            'videos' => Video::latest()->get(),
            'totalCost' => Video::sum('cost'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('video-demo::studio.create', [
            'models' => Pricing::options(),
            'styles' => VideoStyle::options(),
            'devices' => Devices::all(),
            'efforts' => self::EFFORTS,
            'defaults' => [
                'prompt' => $request->query('prompt', ''),
                'model' => config('video-demo.ai.model'),
                'effort' => config('video-demo.ai.effort'),
                'target_seconds' => 60,
                'devices' => Devices::defaults(),
            ],
            'hasKey' => filled(config('video-demo.ai.api_key')),
        ]);
    }

    public function estimate(Request $request, CostEstimator $estimator, CodeTools $code): JsonResponse
    {
        $data = $request->validate($this->rules(forEstimate: true));

        return response()->json($estimator->estimate($data['model'], $data['effort'], (int) $data['target_seconds'], $code->routeCount()));
    }

    /** "Complete with AI": turns a rough note into the form's fields */
    public function complete(Request $request, FormAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['nullable', 'string', 'max:4000'],
            'model' => ['required', Rule::in(array_keys(config('video-demo.ai.models')))],
        ]);

        try {
            return response()->json($assistant->complete((string) ($data['prompt'] ?? ''), $data['model']));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => $e instanceof PlanningFailed ? $e->getMessage() : 'Claude could not be reached. Check ANTHROPIC_API_KEY and try again.'], 502);
        }
    }

    public function store(Request $request, CostEstimator $estimator, CodeTools $code): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $estimate = $estimator->estimate($data['model'], $data['effort'], (int) $data['target_seconds'], $code->routeCount());
        $style = VideoStyle::findOrFail($data['style_id']);
        unset($data['style_id']);
        $data['devices'] = array_values(array_intersect(Devices::keys(), $data['devices']));

        $video = Video::create([
            ...$data,
            'style' => $style->snapshot(),
            'status' => VideoStatus::Queued,
            'estimate_low' => $estimate['low'],
            'estimate_high' => $estimate['high'],
        ]);
        $video->addLog('Queued');

        GenerateVideo::dispatch($video);

        return redirect()->route('video-demo.studio.show', $video);
    }

    public function show(Video $video): View
    {
        return view('video-demo::studio.show', [
            'video' => $video,
            'devices' => Devices::all(),
            'efforts' => self::EFFORTS,
        ]);
    }

    public function status(Video $video): JsonResponse
    {
        return response()->json([
            'status' => $video->status->value,
            'label' => $video->status->label(),
            'working' => $video->status->isWorking(),
            'cost' => $video->cost,
            'log' => array_slice($video->log ?? [], -40),
            'error' => $video->error,
        ]);
    }

    public function file(Video $video, string $device, string $kind): BinaryFileResponse
    {
        $path = $video->workFile($device, $kind);
        $disk = Storage::disk(config('video-demo.storage.work_disk'));
        abort_unless($disk->exists($path), 404);

        // A file response supports range requests, which browsers need to seek in video
        return response()->file($disk->path($path), ['Cache-Control' => 'no-store']);
    }

    public function update(Request $request, Video $video): RedirectResponse
    {
        $video->update($request->validate([
            'title' => ['required', 'string', 'max:160'],
            'visibility' => ['required', Rule::in(['public', 'private'])],
        ]));

        return back()->with('status', $video->isPublished() ? 'Saved. Publish again to update the library.' : 'Saved.');
    }

    public function regenerate(Request $request, Video $video): RedirectResponse
    {
        abort_if($video->status->isWorking(), 409, 'This video is still being made.');

        $video->update([
            ...$request->validate([
                'prompt' => ['required', 'string', 'max:4000'],
                'target_seconds' => ['required', 'integer', 'min:10', 'max:'.config('video-demo.limits.max_seconds', 300)],
                'effort' => ['required', Rule::in(self::EFFORTS)],
            ]),
            'status' => VideoStatus::Queued,
            'error' => null,
        ]);
        $video->addLog('Queued for a new plan');
        GenerateVideo::dispatch($video);

        return redirect()->route('video-demo.studio.show', $video);
    }

    public function rerecord(Request $request, Video $video): RedirectResponse
    {
        abort_if($video->status->isWorking(), 409, 'This video is still being made.');
        abort_if(empty($video->plan['steps']), 422, 'This video has no plan to record yet.');

        $chosen = $request->validate([
            'devices' => ['required', 'array', 'min:1'],
            'devices.*' => ['string', Rule::in(Devices::keys())],
        ])['devices'];

        $video->update([
            'status' => VideoStatus::Queued,
            'error' => null,
            'devices' => array_values(array_intersect(Devices::keys(), $chosen)),
        ]);
        $video->addLog('Queued to record the same plan again');
        GenerateVideo::dispatch($video, replan: false);

        return redirect()->route('video-demo.studio.show', $video);
    }

    public function publish(Video $video, Publisher $publisher): RedirectResponse
    {
        try {
            $publisher->publish($video);
        } catch (Throwable $e) {
            return back()->with('error', 'Could not publish: '.$e->getMessage());
        }

        return back()->with('status', 'Published to the library.');
    }

    public function unpublish(Video $video, Publisher $publisher): RedirectResponse
    {
        $publisher->unpublish($video);

        return back()->with('status', 'Removed from the library.');
    }

    public function destroy(Video $video, Publisher $publisher): RedirectResponse
    {
        if ($video->isPublished()) {
            $publisher->unpublish($video);
        }
        Storage::disk(config('video-demo.storage.work_disk'))->deleteDirectory($video->workPath());
        $video->delete();

        return redirect()->route('video-demo.studio.index')->with('status', 'Video deleted.');
    }

    /** Questions users asked in the library: from production when it is set up, else from this app */
    public function requests(): JsonResponse
    {
        $production = config('video-demo.studio.production_url');
        $token = config('video-demo.library.requests_token');

        if ($production && $token) {
            $url = rtrim($production, '/').'/'.trim(config('video-demo.library.private.prefix'), '/').'/api/requests';
            $response = Http::withToken($token)->acceptJson()->timeout(10)->get($url);

            return $response->successful()
                ? response()->json(['source' => $production, 'requests' => $response->json('requests', [])])
                : response()->json(['source' => $production, 'error' => "Production answered with HTTP {$response->status()}."], 502);
        }

        return response()->json([
            'source' => 'local',
            'requests' => VideoRequest::where('status', 'open')->latest()->limit(100)->get(['id', 'question', 'page_url', 'created_at']),
        ]);
    }

    protected function rules(bool $forEstimate = false): array
    {
        $rules = [
            'model' => ['required', Rule::in(array_keys(config('video-demo.ai.models')))],
            'effort' => ['required', Rule::in(self::EFFORTS)],
            'target_seconds' => ['required', 'integer', 'min:10', 'max:'.config('video-demo.limits.max_seconds', 300)],
        ];

        return $forEstimate ? $rules : [
            ...$rules,
            'title' => ['nullable', 'string', 'max:160'],
            'prompt' => ['required', 'string', 'max:4000'],
            'start_path' => ['nullable', 'string', 'max:255', 'starts_with:/'],
            'visibility' => ['required', Rule::in(['public', 'private'])],
            'devices' => ['required', 'array', 'min:1'],
            'devices.*' => ['string', Rule::in(Devices::keys())],
            'style_id' => ['required', 'integer', Rule::exists(VideoStyle::class, 'id')],
        ];
    }
}
