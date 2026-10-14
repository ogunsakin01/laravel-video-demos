<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\CostEstimator;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\FormAssistant;
use Ogunsakin01\VideoDemo\Ai\PlanningFailed;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoIdea;
use Ogunsakin01\VideoDemo\Models\VideoRequest;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\RecordAs;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Studio\Embeds;
use Ogunsakin01\VideoDemo\Studio\Examples;
use Ogunsakin01\VideoDemo\Studio\Pages;
use Ogunsakin01\VideoDemo\VideoDemo;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Creates, records and publishes videos in the studio.
 */
class VideoController extends Controller
{
    /**
     * The effort levels a video can be planned with.
     */
    protected const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * Display the studio's list of videos.
     */
    public function index(): View
    {
        return view('video-demo::studio.index', [
            'videos' => Video::latest()->get(),
            'totalCost' => Video::sum('cost'),
            'queueWarning' => VideoDemoServiceProvider::queueWarning(),
        ]);
    }

    /**
     * Display the new-video form.
     */
    public function create(Request $request): View
    {
        return view('video-demo::studio.create', [
            'models' => Pricing::options(),
            'styles' => VideoStyle::options(),
            'devices' => Devices::all(),
            'efforts' => self::EFFORTS,
            'defaults' => [
                'prompt' => $request->query('prompt', ''),
                'title' => $request->query('title', ''),
                'start_path' => $request->query('start_path', ''),
                'style' => $request->query('style'),
                'model' => Pricing::defaultModel(),
                'effort' => config('video-demo.ai.effort'),
                'target_seconds' => (int) $request->query('target_seconds', 60),
                'devices' => Devices::defaults(),
                'max_cost' => (float) config('video-demo.limits.max_cost_per_video', 0.5),
                'speed' => (float) $request->query('speed', Recorder::speed()),
                'max_steps' => $request->query('max_steps'),
                'record_as' => $request->query('record_as', config('video-demo.recorder.login_as') ? '' : RecordAs::GUEST),
                'sample_data' => (bool) $request->query('sample_data', config('video-demo.sample_data.default', true)),
                'idea' => $request->query('idea'),
            ],
            'recordAs' => RecordAs::options(),
            'modelNotes' => collect(config('video-demo.ai.models'))->map(fn ($p) => ($p['reliable'] ?? true) ? '' : 'cheapest, but plans less reliably, so it may need more repairs')->all(),
            'queueWarning' => VideoDemoServiceProvider::queueWarning(),
            'hasKey' => Drivers::anyKey(),
        ]);
    }

    /**
     * Display sample videos made with this package.
     */
    public function examples(): View
    {
        abort_unless(Examples::enabled(), 404);

        return view('video-demo::studio.examples', [
            'examples' => Examples::all(),
            'devices' => Devices::all(),
        ]);
    }

    /**
     * Estimate what a video will cost to make.
     */
    public function estimate(Request $request, CostEstimator $estimator, CodeTools $code): JsonResponse
    {
        $data = $request->validate($this->rules(forEstimate: true));

        return response()->json($estimator->estimate($data['model'], $data['effort'], (int) $data['target_seconds'], $code->routeCount()));
    }

    /**
     * List the pages a video can start on, as the chosen user.
     */
    public function pages(Request $request, Pages $pages): JsonResponse
    {
        $data = $request->validate([
            'record_as' => ['nullable', 'string', Rule::in(array_keys(RecordAs::options()))],
            'fresh' => ['nullable', 'boolean'],
        ]);

        try {
            return response()->json($pages->for(($data['record_as'] ?? '') === '' ? null : $data['record_as'], (bool) ($data['fresh'] ?? false)));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'Could not check the pages: '.$e->getMessage()], 502);
        }
    }

    /**
     * Fill in the new-video form from a rough note, using the AI model.
     */
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

            return response()->json(['error' => $e instanceof PlanningFailed ? $e->getMessage() : 'The AI provider could not be reached. Check your API key and try again.'], 502);
        }
    }

    /**
     * Store a new video and queue it to be made.
     */
    public function store(Request $request, CostEstimator $estimator, CodeTools $code): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $estimate = $estimator->estimate($data['model'], $data['effort'], (int) $data['target_seconds'], $code->routeCount());
        $style = VideoStyle::findOrFail($data['style_id']);
        $ideaId = $data['idea'] ?? null;
        unset($data['style_id'], $data['idea']);
        $data['devices'] = array_values(array_intersect(Devices::keys(), $data['devices']));
        $data['record_as'] = ($data['record_as'] ?? '') === '' ? null : $data['record_as'];
        $data['sample_data'] = $request->boolean('sample_data');

        $video = Video::create([
            ...$data,
            'record_as_label' => RecordAs::label($data['record_as']),
            'style' => $style->snapshot(),
            'status' => VideoStatus::Queued,
            'estimate_low' => $estimate['low'],
            'estimate_high' => $estimate['high'],
        ]);
        $video->addLog('Queued');
        if ($ideaId) {
            VideoIdea::whereKey($ideaId)->update(['video_id' => $video->id]);
        }

        GenerateVideo::dispatch($video);

        return redirect()->route('video-demo.studio.show', $video);
    }

    /**
     * Display the given video.
     */
    public function show(Video $video): View
    {
        return view('video-demo::studio.show', [
            'video' => $video,
            'devices' => Devices::all(),
            'efforts' => self::EFFORTS,
            'styles' => VideoStyle::options(),
            'recordAs' => RecordAs::options() + [$video->record_as ?? '' => $video->record_as_label ?: RecordAs::label($video->record_as)],
            'models' => Pricing::options(),
            'queueWarning' => $video->status->isWorking() ? VideoDemoServiceProvider::queueWarning() : null,
        ]);
    }

    /**
     * Get the given video's progress, for the page to poll.
     */
    public function status(Video $video): JsonResponse
    {
        return response()->json([
            'status' => $video->status->value,
            'label' => $video->status->label(),
            'working' => $video->status->isWorking(),
            'cost' => $video->cost,
            'log' => array_slice($video->log ?? [], -40),
            'error' => $video->error,
            'issues' => $video->issues ?? [],
            'stopping' => $video->stop_requested,
        ]);
    }

    /**
     * Send a recorded video or poster from the work disk.
     */
    public function file(Video $video, string $device, string $kind): BinaryFileResponse
    {
        $path = $video->workFile($device, $kind);
        $disk = Storage::disk(config('video-demo.storage.work_disk'));
        abort_unless($disk->exists($path), 404);

        // A file response supports range requests, which browsers need to seek in a video.
        return response()->file($disk->path($path), ['Cache-Control' => 'no-store']);
    }

    /**
     * Update the given video's title and visibility.
     */
    public function update(Request $request, Video $video): RedirectResponse
    {
        $video->update($request->validate([
            'title' => ['required', 'string', 'max:160'],
            'visibility' => ['required', Rule::in(['public', 'private'])],
        ]));

        return back()->with('status', $video->isPublished() ? 'Saved. Publish again to update the library.' : 'Saved.');
    }

    /**
     * Queue the given video to be planned and recorded again.
     */
    public function regenerate(Request $request, Video $video): RedirectResponse
    {
        abort_if($video->status->isWorking(), 409, 'This video is still being made.');

        // A new style is copied onto the video, like on the new-video form.
        $style = $request->filled('style_id') ? VideoStyle::find($request->validate(['style_id' => ['integer', Rule::exists(VideoStyle::class, 'id')]])['style_id']) : null;

        $video->update([
            ...($style ? ['style' => $style->snapshot()] : []),
            ...$request->validate([
                'prompt' => ['required', 'string', 'max:4000'],
                'target_seconds' => ['required', 'integer', 'min:10', 'max:'.config('video-demo.limits.max_seconds', 300)],
                'effort' => ['required', Rule::in(self::EFFORTS)],
                'max_cost' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
                'max_steps' => ['nullable', 'integer', 'min:3', 'max:250'],
                'speed' => ['nullable', 'numeric', 'min:0.25', 'max:4'],
                'sample_data' => ['nullable', 'boolean'],
            ]),
            'sample_data' => $request->has('sample_data') ? $request->boolean('sample_data') : $video->sample_data,
            'status' => VideoStatus::Queued,
            'error' => null,
            'stop_requested' => false,
        ]);
        $video->addLog('Queued for a new plan');
        GenerateVideo::dispatch($video);

        return redirect()->route('video-demo.studio.show', $video);
    }

    /**
     * Make the given video again from the "Make it again" form: with a new
     * plan from the AI, or by recording its plan again at no AI cost.
     *
     * A new plan is made when asked for, when there is none, and when the
     * video's user or data changes, since the old plan was made for them.
     */
    public function again(Request $request, Video $video, CostEstimator $estimator, CodeTools $code): RedirectResponse
    {
        abort_if($video->status->isWorking(), 409, 'This video is still being made.');

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'target_seconds' => ['required', 'integer', 'min:10', 'max:'.config('video-demo.limits.max_seconds', 300)],
            'speed' => ['nullable', 'numeric', 'min:0.25', 'max:4'],
            'devices' => ['required', 'array', 'min:1'],
            'devices.*' => ['string', Rule::in(Devices::keys())],
            'style_id' => ['nullable', 'integer', Rule::exists(VideoStyle::class, 'id')],
            'record_as' => ['nullable', 'string', Rule::in(array_keys(RecordAs::options() + [$video->record_as ?? '' => '']))],
            'start_path' => ['nullable', 'string', 'max:255', 'starts_with:/'],
            'sample_data' => ['nullable', 'boolean'],
            'model' => ['required', Rule::in(array_keys(config('video-demo.ai.models')))],
            'effort' => ['required', Rule::in(self::EFFORTS)],
            'max_cost' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'max_steps' => ['nullable', 'integer', 'min:3', 'max:250'],
            'new_plan' => ['nullable', 'boolean'],
        ], ['devices.required' => 'Choose at least one version.']);

        $recordAs = ($data['record_as'] ?? '') === '' ? null : $data['record_as'];
        $sampleData = $request->boolean('sample_data');
        $forced = $recordAs !== $video->record_as || $sampleData !== $video->sample_data;
        $replan = $request->boolean('new_plan') || $forced || empty($video->plan['steps']);
        $style = isset($data['style_id']) ? VideoStyle::find($data['style_id']) : null;
        $estimate = $replan ? $estimator->estimate($data['model'], $data['effort'], (int) $data['target_seconds'], $code->routeCount()) : null;

        $video->update([
            'prompt' => $data['prompt'],
            'target_seconds' => (int) $data['target_seconds'],
            'speed' => $data['speed'] ?? $video->speed,
            'devices' => array_values(array_intersect(Devices::keys(), $data['devices'])),
            'record_as' => $recordAs,
            'record_as_label' => RecordAs::label($recordAs),
            'start_path' => $data['start_path'] ?? null,
            'sample_data' => $sampleData,
            'model' => $data['model'],
            'effort' => $data['effort'],
            'max_cost' => $data['max_cost'] ?? null,
            'max_steps' => $data['max_steps'] ?? null,
            ...($style ? ['style' => $style->snapshot()] : []),
            ...($estimate ? ['estimate_low' => $estimate['low'], 'estimate_high' => $estimate['high']] : []),
            'status' => VideoStatus::Queued,
            'error' => null,
            'issues' => null,
            'stop_requested' => false,
        ]);
        $video->addLog(match (true) {
            ! $replan => 'Queued to record the same plan again',
            $forced && ! $request->boolean('new_plan') => 'Queued for a new plan: the user or the data changed, so the old plan may not fit',
            default => 'Queued for a new plan',
        });
        GenerateVideo::dispatch($video, replan: $replan);

        return redirect()->route('video-demo.studio.show', $video);
    }

    /**
     * Queue the given video's plan to be recorded again.
     */
    public function rerecord(Request $request, Video $video): RedirectResponse
    {
        abort_if($video->status->isWorking(), 409, 'This video is still being made.');
        abort_if(empty($video->plan['steps']), 422, 'This video has no plan to record yet.');

        $data = $request->validate([
            'devices' => ['required', 'array', 'min:1'],
            'devices.*' => ['string', Rule::in(Devices::keys())],
            'speed' => ['nullable', 'numeric', 'min:0.25', 'max:4'],
        ]);

        $video->update([
            'status' => VideoStatus::Queued,
            'error' => null,
            'stop_requested' => false,
            'devices' => array_values(array_intersect(Devices::keys(), $data['devices'])),
            'speed' => $data['speed'] ?? $video->speed,
        ]);
        $video->addLog('Queued to record the same plan again');
        GenerateVideo::dispatch($video, replan: false);

        return redirect()->route('video-demo.studio.show', $video);
    }

    /**
     * Try a failed video again with the same settings.
     *
     * When recording failed, the same plan can be recorded again at no AI cost;
     * otherwise the video gets a new plan.
     */
    public function retry(Request $request, Video $video): RedirectResponse
    {
        abort_if($video->status->isWorking(), 409, 'This video is still being made.');

        // Switching to sample data needs a new plan: the old one was made for other data.
        $toSample = $request->boolean('sample_data') && ! $video->sample_data;
        $replan = $toSample || $request->input('mode') !== 'record' || empty($video->plan['steps']);

        $video->update(['status' => VideoStatus::Queued, 'error' => null, 'stop_requested' => false, 'issues' => null, ...($toSample ? ['sample_data' => true] : [])]);
        $video->addLog($toSample ? 'Trying again on sample data, with a new plan' : ($replan ? 'Trying again with a new plan' : 'Trying again: recording the same plan'));
        GenerateVideo::dispatch($video, replan: $replan);

        return redirect()->route('video-demo.studio.show', $video);
    }

    /**
     * Stop the given video being made.
     *
     * A queued video stops at once. A running one stops at its next safe point:
     * between AI requests, or by closing the browser.
     */
    public function stop(Video $video): RedirectResponse
    {
        if ($video->status === VideoStatus::Queued) {
            $video->update(['stop_requested' => true]);
            $video->moveTo(VideoStatus::Stopped, 'Stopped by you before it started.');
        } elseif ($video->status->isWorking()) {
            $video->update(['stop_requested' => true]);
            $video->addLog('Stopping…');
        }

        return back();
    }

    /**
     * Publish the given video to the library.
     */
    public function publish(Video $video, Publisher $publisher): RedirectResponse
    {
        try {
            $publisher->publish($video);
        } catch (Throwable $e) {
            return back()->with('error', 'Could not publish: '.$e->getMessage());
        }

        return back()->with('status', 'Published to the library.');
    }

    /**
     * Publish or unpublish the videos ticked on the list.
     *
     * Videos that aren't ready, or are already as asked, are skipped and counted.
     */
    public function bulk(Request $request, Publisher $publisher, Manifest $manifest): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['publish', 'unpublish', 'delete'])],
            'videos' => ['required', 'array', 'min:1'],
            'videos.*' => ['integer'],
        ], ['videos.required' => 'Tick at least one video.']);

        if ($data['action'] === 'delete') {
            $deleted = 0;
            foreach (Video::whereKey($data['videos'])->get() as $video) {
                if (! $video->status->isWorking()) {
                    app(VideoDemo::class)->delete($video);
                    $deleted++;
                }
            }
            $kept = count($data['videos']) - $deleted;

            return redirect()->route('video-demo.studio.index')->with('status', trim(
                ($deleted === 1 ? 'Deleted 1 video.' : "Deleted $deleted videos.").($kept ? " $kept still being made ".($kept === 1 ? 'was' : 'were').' kept; stop them first.' : '')
            ));
        }

        $done = $skipped = 0;
        $failed = [];
        foreach (Video::whereKey($data['videos'])->get() as $video) {
            if ($data['action'] === 'unpublish') {
                if (! $video->isPublished()) {
                    $skipped++;

                    continue;
                }
                $publisher->unpublish($video);
                $done++;

                continue;
            }

            $live = $video->isPublished() && ($manifest->find($video->slug, 'private')['version'] ?? null) === $video->version;
            if ($video->status !== VideoStatus::Ready || ! $video->hasRecording() || $live) {
                $skipped++;

                continue;
            }

            try {
                $publisher->publish($video);
                $done++;
            } catch (Throwable $e) {
                $failed[] = ($video->title ?: $video->slug).': '.$e->getMessage();
            }
        }

        $verb = $data['action'] === 'publish' ? 'Published' : 'Unpublished';
        $message = sprintf('%s %d %s.', $verb, $done, Str::plural('video', $done))
            .($skipped ? sprintf(' Skipped %d: %s.', $skipped, $data['action'] === 'publish' ? 'not ready, or already published at their latest version' : 'not published') : '');

        return redirect()->route('video-demo.studio.index')
            ->with('status', $message)
            ->with('error', $failed ? 'Could not publish '.implode('; ', $failed) : null);
    }

    /**
     * Remove the given video from the library.
     */
    public function unpublish(Video $video, Publisher $publisher): RedirectResponse
    {
        $publisher->unpublish($video);

        return back()->with('status', 'Removed from the library.');
    }

    /**
     * Remove the given video and its working files.
     */
    public function destroy(Video $video): RedirectResponse
    {
        abort_if($video->status->isWorking(), 409, 'This video is still being made. Stop it first.');
        app(VideoDemo::class)->delete($video);

        return redirect()->route('video-demo.studio.index')->with('status', 'Video deleted.');
    }

    /**
     * Show what deleting the given videos would affect, before deleting them:
     * whether each is in the library, and the pages in the app's code that
     * show it.
     */
    public function confirmDelete(Request $request): View|RedirectResponse
    {
        $ids = array_filter(array_map('intval', (array) $request->query('videos', [])));
        $videos = Video::whereKey($ids)->get();
        if ($videos->isEmpty()) {
            return redirect()->route('video-demo.studio.index')->with('error', 'Tick at least one video.');
        }

        return view('video-demo::studio.delete', [
            'videos' => $videos,
            'embeds' => Embeds::find($videos->pluck('slug')->all()),
        ]);
    }

    /**
     * List the videos users asked for in the library.
     *
     * They come from production when it is set up, and from this app otherwise.
     */
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

    /**
     * Get the validation rules for the new-video form.
     */
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
            'max_cost' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'max_steps' => ['nullable', 'integer', 'min:3', 'max:250'],
            'speed' => ['nullable', 'numeric', 'min:0.25', 'max:4'],
            'record_as' => ['nullable', 'string', Rule::in(array_keys(RecordAs::options()))],
            'sample_data' => ['nullable', 'boolean'],
            'idea' => ['nullable', 'integer'],
            'devices' => ['required', 'array', 'min:1'],
            'devices.*' => ['string', Rule::in(Devices::keys())],
            'style_id' => ['required', 'integer', Rule::exists(VideoStyle::class, 'id')],
        ];
    }
}
