<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\CostEstimator;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\FormAssistant;
use Ogunsakin01\VideoDemo\Ai\PlanningFailed;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\ExportVideo;
use Ogunsakin01\VideoDemo\Jobs\FindSpots;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Jobs\VerifyVideo;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Library\Restorer;
use Ogunsakin01\VideoDemo\Library\Stats;
use Ogunsakin01\VideoDemo\Models\Spend;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoIdea;
use Ogunsakin01\VideoDemo\Models\VideoRequest;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\Exporter;
use Ogunsakin01\VideoDemo\Recording\PestTest;
use Ogunsakin01\VideoDemo\Recording\RecordAs;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Studio\Embeds;
use Ogunsakin01\VideoDemo\Studio\Examples;
use Ogunsakin01\VideoDemo\Studio\Pages;
use Ogunsakin01\VideoDemo\Studio\Progress;
use Ogunsakin01\VideoDemo\Support\Doctor;
use Ogunsakin01\VideoDemo\Support\Language;
use Ogunsakin01\VideoDemo\Support\Worker;
use Ogunsakin01\VideoDemo\VideoDemo;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Ogunsakin01\VideoDemo\Voice\Music;
use Ogunsakin01\VideoDemo\Voice\Voices;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Creates, records and publishes videos in the studio.
 */
class VideoController extends Controller
{
    /**
     * Display the studio's list of videos.
     */
    public function index(Restorer $restorer): View
    {
        return view('video-demo::studio.index', [
            'videos' => Video::latest()->get(),
            'totalCost' => Spend::total(),
            'queueWarning' => VideoDemoServiceProvider::queueWarning(),
            'lost' => rescue(fn () => $restorer->missing(), [], false),
        ]);
    }

    /**
     * Get what isn't set up for making videos, for the studio's notice: the
     * checks video-demo:doctor runs, kept for ten minutes as some start
     * programs. ?fresh=1 checks again.
     */
    public function setup(Request $request, Doctor $doctor): JsonResponse
    {
        if ($request->boolean('fresh')) {
            Cache::forget('video-demo.studio.setup');
        }
        $checks = Cache::remember('video-demo.studio.setup', 600, fn () => $doctor->run(web: true));

        // The studio's pages say what's wrong with the queue themselves.
        return response()->json(['problems' => array_values(array_filter($checks, fn ($c) => $c['status'] === 'fail' && $c['check'] !== 'The queue'))]);
    }

    /**
     * Download the video's plan as a Pest browser test.
     */
    public function pestTest(Video $video): Response
    {
        abort_unless($video->hasPlan(), 404);

        return response(PestTest::for($video), 200, [
            'Content-Type' => 'text/x-php; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.PestTest::fileName($video).'"',
        ]);
    }

    /**
     * Bring back the published videos the studio's database no longer has.
     */
    public function restore(Restorer $restorer): RedirectResponse
    {
        $restored = collect($restorer->restore());
        $unplanned = $restored->reject(fn (Video $video) => $video->hasPlan())->count();

        return redirect()->route('video-demo.studio.index')->with('status', $restored->isEmpty()
            ? 'Nothing to bring back: the studio has every published video.'
            : sprintf('Brought back %d published %s.', $restored->count(), Str::plural('video', $restored->count()))
                .($unplanned ? sprintf(' %d %s published before plans were saved with videos, so to record %s again, ask for a new plan.', $unplanned, $unplanned === 1 ? 'was' : 'were', $unplanned === 1 ? 'it' : 'them') : ''));
    }

    /**
     * Get the total spent on AI, and the status and cost of the given videos,
     * for the videos page to keep up to date while videos are being made.
     */
    public function spending(Request $request): JsonResponse
    {
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $request->query('videos', '')))), 0, 200);

        return response()->json([
            'total' => Spend::total(),
            'worker' => Worker::running(),
            'videos' => Video::whereKey($ids)->get(['id', 'status', 'cost', 'duration_seconds'])->mapWithKeys(fn (Video $v) => [$v->id => [
                'status' => $v->status->value,
                'label' => $v->status->label(),
                'class' => $v->status->isWorking() ? 'working' : $v->status->value,
                'working' => $v->status->isWorking(),
                'cost' => round($v->cost, 4),
                'length' => $v->duration_seconds ? gmdate('i:s', (int) round($v->duration_seconds)) : null,
            ]]),
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
            'efforts' => Pricing::EFFORTS,
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
                'voice' => $request->query('voice', config('video-demo.voice.default')),
                'language' => $request->query('language'),
                'music' => $request->query('music', config('video-demo.music.default')),
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
     * Suggest a better description for making the given video again, from
     * how its last attempt went. Nothing is started; the form is filled in.
     */
    public function improve(Request $request, Video $video, FormAssistant $assistant): JsonResponse
    {
        abort_if($video->status->isWorking(), 409, 'This video is still being made.');

        $data = $request->validate([
            'wish' => ['nullable', 'string', 'max:1000'],
            'model' => ['required', Rule::in(array_keys(config('video-demo.ai.models')))],
        ]);

        try {
            $improved = $assistant->improve($video, (string) ($data['wish'] ?? ''), $data['model']);

            // What the video has cost in all, now including this request.
            return response()->json([...$improved, 'video_cost' => $video->fresh()->cost]);
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
        $data = [...$data, ...$this->sound($data)];

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
            'efforts' => Pricing::EFFORTS,
            'styles' => VideoStyle::options(),
            'recordAs' => RecordAs::options() + [$video->record_as ?? '' => $video->record_as_label ?: RecordAs::label($video->record_as)],
            'models' => Pricing::options(),
            'hasKey' => Drivers::anyKey(),
            'queueWarning' => $video->status->isWorking() ? VideoDemoServiceProvider::queueWarning() : null,
        ]);
    }

    /**
     * Get how the published videos are watched and the searches that found
     * nothing, from production when it is set up. Kept for a minute.
     */
    public function stats(Stats $stats): JsonResponse
    {
        return response()->json(Cache::remember('video-demo.studio.stats', 60, fn () => $stats->fetch()));
    }

    /**
     * Get how the given video is watched: its counts, and where viewers stop.
     */
    public function videoStats(Video $video, Stats $stats): JsonResponse
    {
        $all = Cache::remember('video-demo.studio.stats', 60, fn () => $stats->fetch());
        $counts = $all['videos'][$video->slug] ?? null;

        return response()->json([
            'source' => $all['source'],
            'error' => $all['error'] ?? null,
            'enabled' => $all['enabled'] ?? Stats::enabled(),
            'days' => $all['days'] ?? 90,
            'counts' => $counts,
            'drop_off' => $counts ? Stats::dropOff($counts, (float) $video->duration_seconds, $video->recordings[$video->primaryDevice()]['chapters'] ?? []) : null,
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
            'progress' => Progress::for($video),
            'spots' => $video->spots['status'] ?? null,
            'spots_progress' => FindSpots::progress($video),
            'verify' => $video->verify_result['status'] ?? null,
            'exports' => collect($video->exports ?? [])->map(fn ($e) => $e['status'])->all(),
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
     * Show the recorder's screenshot of the page a video failed on.
     */
    public function errorScreenshot(Video $video): BinaryFileResponse
    {
        $disk = Storage::disk(config('video-demo.storage.work_disk'));
        abort_unless(! empty($video->error_details['step']['screenshot']) && $disk->exists($video->workPath('error.jpg')), 404);

        return response()->file($disk->path($video->workPath('error.jpg')), ['Cache-Control' => 'no-store']);
    }

    /**
     * Queue a copy of the video as a GIF, or a square or vertical MP4.
     */
    public function export(Request $request, Video $video): RedirectResponse
    {
        abort_unless($video->isFinished(), 409, 'Only a finished video can be copied.');
        $data = $request->validate([
            'format' => ['required', Rule::in(array_keys(Exporter::FORMATS))],
            'device' => ['nullable', Rule::in($video->recordedDevices())],
        ]);

        // Started once, even when the button is pressed twice.
        if ($video->startExport($data['format'])) {
            ExportVideo::dispatch($video, $data['format'], $data['device'] ?? null);
        }

        return redirect()->to(route('video-demo.studio.show', $video).'#vd-downloads');
    }

    /**
     * Download the video's copy in a format.
     */
    public function download(Video $video, string $format): BinaryFileResponse
    {
        $disk = Storage::disk(config('video-demo.storage.work_disk'));
        $file = Exporter::file($video, $format);
        abort_unless((($video->exports ?? [])[$format]['status'] ?? null) === 'ready' && $disk->exists($file), 404);

        return response()->download($disk->path($file), $video->slug.($format === 'gif' ? '' : "-$format").'.'.Exporter::FORMATS[$format]['ext'], ['Cache-Control' => 'no-store']);
    }

    /**
     * Make the video in another language, from its plan.
     */
    public function translate(Request $request, Video $video, VideoDemo $demo): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['required', 'string', Rule::in(array_keys(Language::options()))],
            'voice' => ['nullable', 'string', Rule::in(array_keys(Voices::all()))],
        ]);

        try {
            $translation = $demo->translate($video, $data['language'], ['voice' => $data['voice'] ?? null]);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(route('video-demo.studio.show', $video).'#vd-languages')->withInput()->withErrors([str_contains(Str::lower($e->getMessage()), 'voice') || str_contains($e->getMessage(), 'speak') ? 'voice' : 'language' => $e->getMessage()]);
        }

        return redirect()->route('video-demo.studio.show', $translation);
    }

    /**
     * Save the plan as edited on the video page, and record it again for
     * free when asked.
     */
    public function updatePlan(Request $request, Video $video, VideoDemo $demo): RedirectResponse
    {
        abort_if($video->status->isWorking(), 409, 'This video is still being made.');

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'steps' => ['required', 'array', 'min:1', 'max:250'],
            'steps.*' => ['array'],
        ], ['steps.required' => 'Keep at least one step.']);

        try {
            $demo->updatePlan($video, ['title' => $data['title'] ?? null, 'summary' => $data['summary'] ?? null, 'steps' => $data['steps']]);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(route('video-demo.studio.show', $video).'#vd-plan')->withInput()->withErrors(['plan' => $e->getMessage()]);
        }

        if ($request->input('then') === 'record') {
            $demo->rerecord($video);

            return redirect()->route('video-demo.studio.show', $video);
        }

        return redirect()->to(route('video-demo.studio.show', $video).'#vd-plan')->with('status', 'Plan saved. Record it again to see the change.');
    }

    /**
     * Queue a check that the video's plan still works in the app.
     */
    public function verify(Video $video): RedirectResponse
    {
        abort_if($video->status->isWorking(), 409, 'This video is still being made.');
        abort_unless($video->hasPlan(), 422, 'This video has no plan to check yet.');

        // Started once, even when the button is pressed twice.
        $started = $video->changeSaved('verify_result', fn (array $check) => ($check['status'] ?? null) === 'working'
            ? null
            : ['status' => 'working', 'at' => now()->toIso8601String()]);
        if ($started) {
            VerifyVideo::dispatch($video);
        }

        return redirect()->to(route('video-demo.studio.show', $video).'#vd-check');
    }

    /**
     * Show the page as it was when the last check failed.
     */
    public function verifyScreenshot(Video $video): BinaryFileResponse
    {
        $disk = Storage::disk(config('video-demo.storage.work_disk'));
        abort_unless(! empty($video->verify_result['screenshot']) && $disk->exists($video->workPath('verify.jpg')), 404);

        return response()->file($disk->path($video->workPath('verify.jpg')), ['Cache-Control' => 'no-store']);
    }

    /**
     * Queue finding where in the app the video could go.
     */
    public function findSpots(Video $video): RedirectResponse
    {
        abort_unless($video->isFinished(), 409, 'Only a finished video can be placed.');

        // Started once, even when the button is pressed twice.
        $started = $video->changeSaved('spots', fn (array $spots) => ($spots['status'] ?? null) === 'working'
            ? null
            : ['status' => 'working', 'at' => now()->toIso8601String(), 'items' => $spots['items'] ?? []]);
        if ($started) {
            FindSpots::dispatch($video);
        }

        return redirect()->to(route('video-demo.studio.show', $video).'#vd-spots');
    }

    /**
     * Show a screenshot of a spot the video could go in.
     */
    public function spotShot(Video $video, string $file): BinaryFileResponse
    {
        $disk = Storage::disk(config('video-demo.storage.work_disk'));
        abort_unless($disk->exists($video->workPath("spots/$file")), 404);

        return response()->file($disk->path($video->workPath("spots/$file")), ['Cache-Control' => 'no-store']);
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
            'effort' => ['required', Rule::in(Pricing::EFFORTS)],
            'max_cost' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'max_steps' => ['nullable', 'integer', 'min:3', 'max:250'],
            ...$this->soundRules($video),
            'new_plan' => ['nullable', 'boolean'],
        ], ['devices.required' => 'Choose at least one version.']);

        $recordAs = ($data['record_as'] ?? '') === '' ? null : $data['record_as'];
        $sampleData = $request->boolean('sample_data');
        // A plan's words are in its language, so another language needs a new plan.
        $language = Language::normalize($data['language'] ?? null) ?? Language::normalize($video->page_language) ?? Language::app();
        $forced = $recordAs !== $video->record_as || $sampleData !== $video->sample_data || ! Language::same($language, $video->languageCode());
        // "Try again" on a stopped or failed video sends this form too, asking
        // for a new plan or the same one whatever the switch says.
        $newPlan = match ($request->query('plan')) {
            'new' => true,
            'same' => false,
            default => $request->boolean('new_plan'),
        };
        $replan = $newPlan || $forced || empty($video->plan['steps']);
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
            ...$this->sound($data),
            ...($style ? ['style' => $style->snapshot()] : []),
            ...($estimate ? ['estimate_low' => $estimate['low'], 'estimate_high' => $estimate['high']] : []),
            'status' => VideoStatus::Queued,
            'error' => null,
            'issues' => null,
            'stop_requested' => false,
        ]);
        $video->addLog(match (true) {
            ! $replan => 'Queued to record the same plan again',
            $forced && ! $newPlan => 'Queued for a new plan: the user, the data or the language changed, so the old plan may not fit',
            default => 'Queued for a new plan',
        });
        GenerateVideo::dispatch($video, replan: $replan);

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
            report($e);

            return back()->with('error', 'Could not publish: '.$e->getMessage());
        }

        return back()->with('status', 'Published to the library.'.Publisher::deployNote());
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
            $deleted = $kept = 0;
            foreach (Video::whereKey($data['videos'])->get() as $video) {
                if ($video->status->isWorking()) {
                    $kept++;

                    continue;
                }
                app(VideoDemo::class)->delete($video);
                $deleted++;
            }

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
            if (! $video->isFinished() || $live) {
                $skipped++;

                continue;
            }

            try {
                $publisher->publish($video);
                $done++;
            } catch (Throwable $e) {
                report($e);
                $failed[] = ($video->title ?: $video->slug).': '.$e->getMessage();
            }
        }

        $verb = $data['action'] === 'publish' ? 'Published' : 'Unpublished';
        $message = sprintf('%s %d %s.', $verb, $done, Str::plural('video', $done))
            .($skipped ? sprintf(' Skipped %d: %s.', $skipped, $data['action'] === 'publish' ? 'not ready, or already published at their latest version' : 'not published') : '')
            .($done ? Publisher::deployNote() : '');

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

        return back()->with('status', 'Removed from the library.'.Publisher::deployNote());
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
            try {
                $response = Http::withToken($token)->acceptJson()->timeout(10)->get($url);
            } catch (Throwable $e) {
                return response()->json(['source' => $production, 'error' => 'Production could not be reached: '.$e->getMessage()], 502);
            }

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
     * Get the rules for the voice-over and music fields: a voice or track
     * that can be used here, or the one the video already has.
     */
    protected function soundRules(?Video $video = null): array
    {
        return [
            'voice' => ['nullable', 'string', Rule::in([...array_keys(Voices::all()), ...array_filter([$video?->voice])])],
            'music' => ['nullable', 'string', Rule::in([...array_keys(Music::options()), ...array_filter([$video?->music])])],
            'music_volume' => ['nullable', 'integer', 'min:0', 'max:100'],
            'language' => ['nullable', 'string', Rule::in([...array_keys(Language::options()), ...array_filter([$video?->language])])],
        ];
    }

    /**
     * Get the voice-over and music from the form's fields, as they are stored.
     *
     * A language chosen for the video must be one its voice speaks.
     *
     * @return array{voice: ?string, music: ?string, music_volume: ?float, language: ?string}
     *
     * @throws ValidationException When the voice doesn't speak the chosen language.
     */
    protected function sound(array $data): array
    {
        $voice = ($data['voice'] ?? '') ?: null;
        $language = Language::normalize($data['language'] ?? null);
        if ($voice && $language && ! Voices::speaks($voice, $language)) {
            throw ValidationException::withMessages(['voice' => Voices::label($voice).' doesn\'t speak '.Language::name($language).'. Choose a voice that does, or no voice.']);
        }

        return [
            'voice' => $voice,
            'music' => ($data['music'] ?? '') ?: null,
            'music_volume' => isset($data['music_volume']) ? round($data['music_volume'] / 100, 2) : null,
            'language' => $language,
        ];
    }

    /**
     * Get the validation rules for the new-video form.
     */
    protected function rules(bool $forEstimate = false): array
    {
        $rules = [
            'model' => ['required', Rule::in(array_keys(config('video-demo.ai.models')))],
            'effort' => ['required', Rule::in(Pricing::EFFORTS)],
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
            ...$this->soundRules(),
            'idea' => ['nullable', 'integer'],
            'devices' => ['required', 'array', 'min:1'],
            'devices.*' => ['string', Rule::in(Devices::keys())],
            'style_id' => ['required', 'integer', Rule::exists(VideoStyle::class, 'id')],
        ];
    }
}
