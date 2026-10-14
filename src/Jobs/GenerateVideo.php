<?php

namespace Ogunsakin01\VideoDemo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Ai\VideoStopped;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Camera;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use RuntimeException;
use Throwable;

/**
 * Makes a video: the AI model writes the plan, the recorder plays it, and a
 * step that fails because the plan was wrong goes back to the model to fix.
 *
 * Pages are checked first, and an error in the app itself stops the job with
 * the problem listed, since no plan can fix it and repairs would only cost
 * money. With $replan false the stored plan is recorded again, at no AI cost.
 *
 * On sample data, the video is recorded against a temporary database made
 * for it. Failing pages then go back to the model too, since they usually
 * mean data is missing, which it can add.
 */
class GenerateVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 1800;

    /**
     * Create a new job instance.
     */
    public function __construct(public Video $video, public bool $replan = true)
    {
        $this->onConnection(config('video-demo.queue.connection') ?: VideoDemoServiceProvider::QUEUE_CONNECTION);
        $this->onQueue(config('video-demo.queue.name'));

        // Allow time for planning, then for recording and encoding each version.
        $this->timeout = (int) max(1800, 900 + count($video->deviceList()) * max(600, $video->target_seconds * 12));
    }

    /**
     * Execute the job.
     */
    public function handle(Planner $planner, Recorder $recorder, Encoder $encoder): void
    {
        $video = $this->video;
        $video->refresh();

        // Stopped while it was waiting in the queue.
        if ($video->status === VideoStatus::Stopped || $video->stop_requested) {
            $this->stopped($video);

            return;
        }
        $video->update(['issues' => null]);
        $dir = Recorder::workDir($video->workPath());
        $maxRepairs = (int) config('video-demo.limits.max_repairs', 2);
        $shouldStop = fn () => $video->stopRequested();
        $session = null;
        $sandbox = null;

        try {
            if ($video->sample_data) {
                $sandbox = $this->sampleDatabase($video, $recorder);
            }

            if ($this->replan) {
                // A broken start page would fail every plan, so check it first at no AI cost.
                // On sample data the model is told instead, as it can add what the page needs.
                $problems = $this->checkPages($recorder, $video, [$video->start_path ?: '/'], 'Checking the start page', fail: ! $sandbox);

                $video->moveTo(VideoStatus::Planning, 'The AI is reading the code');
                $session = $planner->plan($video, $sandbox, $problems);
                $plan = $session->plan;
                $video->addLog(sprintf('Plan ready: %d steps, about %d seconds', count($plan->steps), $plan->estimatedSeconds($video->playbackSpeed())));
            } else {
                $plan = Plan::fromArray($video->plan ?? []);
            }

            $devices = $video->deviceList();

            // Every version plays the same plan. A failure on any of them goes
            // back to the AI, and the corrected plan is recorded on all again.
            while (true) {
                $plan = $plan->fitTo(min($video->target_seconds, (int) config('video-demo.limits.max_seconds', 300)), $video->playbackSpeed());
                $video->update(['plan' => $plan->toArray()]);

                $canRepair = $session?->sampleData && $session->repairs < $maxRepairs;
                $problems = $this->checkPages($recorder, $video, $plan->paths(), 'Checking the pages in the plan', fail: ! $canRepair);
                if ($problems !== []) {
                    $video->moveTo(VideoStatus::Planning, 'Asking the AI to fix the data or the plan');
                    $planner->repairPages($session, $video, $problems);
                    $plan = $session->plan;

                    continue;
                }

                $results = [];
                $failure = null;
                foreach ($devices as $device) {
                    $this->stopIfAsked($video);
                    $video->moveTo(VideoStatus::Recording, 'Recording the '.Devices::get($device)['label'].' version');
                    $result = $recorder->record($plan, "$dir/$device", $video->style ?? [], $device, $video->record_as, $shouldStop, $video->playbackSpeed());
                    $video->addLog($result->summary());

                    if (! $result->ok) {
                        $failure = $result;
                        break;
                    }
                    $results[$device] = $result;
                }

                if (! $failure) {
                    break;
                }

                // On sample data an app error is often missing data, which the model can add.
                $sampleFix = $session?->sampleData && $failure->appError && $failure->failedStep !== null && $session->repairs < $maxRepairs;
                if (($issue = $failure->issue()) && ! $sampleFix) {
                    $video->update(['issues' => [$issue]]);

                    throw new RuntimeException("The app itself failed at {$issue['path']}: {$issue['problem']}. Fix it, then try again; asking the AI to repair the plan wouldn't help.");
                }

                if (! $session || ! ($failure->canRepair() || $sampleFix) || $session->repairs >= $maxRepairs) {
                    throw new RuntimeException($failure->summary().($session ? '' : '. Try again with a new plan to let the AI fix it.'));
                }

                $this->stopIfAsked($video);
                $video->moveTo(VideoStatus::Planning, 'Asking the AI to fix the plan');
                $planner->repair($session, $video, $failure);
                $plan = $session->plan;
            }

            $this->stopIfAsked($video);
        } catch (VideoStopped $e) {
            $this->stopped($video);

            return;
        } finally {
            $sandbox?->delete();
            $recorder->sandbox = null;
        }

        $video->moveTo(VideoStatus::Encoding, count($results) > 1 ? 'Encoding '.count($results).' versions' : 'Encoding the video');
        $work = Storage::disk(config('video-demo.storage.work_disk'));
        $recordings = [];
        foreach ($results as $device => $result) {
            $mp4 = $work->path($video->workFile($device));
            $poster = $work->path($video->workFile($device, 'poster'));
            $encoder->encode($result->video, $mp4, $poster, $result->trimSeconds, $video->playbackSpeed());

            // Styles with zoom move a camera in on each step; the video stays as it is if that fails.
            if (! empty($video->style['zoom']) && $result->focus !== []) {
                $screen = Devices::get($device);
                $keys = Camera::keyframes($result->focus, $screen['width'], $screen['height'], $screen['touch'] ? 1.5 : 1.8);
                if ($recorder->applyCamera($mp4, $keys, $screen['width'], $screen['height'], $encoder)) {
                    $encoder->poster($mp4, $poster);
                } else {
                    $video->addLog('The zoom could not be added to the '.$screen['label'].' version, so it plays without it.');
                }
            }
            File::deleteDirectory("$dir/$device");
            $recordings[$device] = ['seconds' => $result->seconds, 'chapters' => $result->chapters];
        }

        // Remove the files of versions that are no longer chosen.
        foreach (array_diff($video->recordedDevices(), $devices) as $old) {
            $work->delete([$video->workFile($old), $video->workFile($old, 'poster')]);
        }

        $primary = $recordings[Devices::primary($devices)];
        $video->update([
            'title' => $video->title ?: $plan->title,
            'duration_seconds' => $primary['seconds'],
            'plan' => [...$plan->toArray(), 'chapters' => $primary['chapters']],
            'recordings' => $recordings,
            'error' => null,
            'version' => $video->version + 1,
        ]);
        $video->moveTo(VideoStatus::Ready, sprintf('Ready. The AI cost $%.4f in total.', $video->cost));
    }

    /**
     * Make the video's sample database, a copy of the base one, and point
     * the recorder at it.
     *
     * A new plan starts from the base; recording a plan again also adds back
     * the records the AI added for it, so its pages find them.
     */
    protected function sampleDatabase(Video $video, Recorder $recorder): Sandbox
    {
        $this->stopIfAsked($video);
        $video->moveTo(VideoStatus::Checking, is_file(Sandbox::dir().'/base-'.Sandbox::fingerprint().'.sqlite')
            ? 'Copying the sample database'
            : 'Building the sample database: running your migrations and seeders once (php artisan video-demo:sample-database does this ahead of time)');
        $sandbox = Sandbox::create($video);
        $recorder->sandbox = $sandbox;

        try {
            $info = $sandbox->prepare($video->record_as);
            foreach ($info['warnings'] as $warning) {
                $video->addLog($warning);
            }
            $video->addLog($info['user']
                ? 'Sample database ready. Signed in as '.($info['user']['email'] ?? 'user #'.$info['user']['id'])
                : 'Sample database ready');

            if ($this->replan) {
                $video->update(['sample_records' => null]);
            } elseif ($records = $video->sample_records) {
                $failed = array_filter($sandbox->seed($records), fn ($r) => ! ($r['ok'] ?? false));
                $video->addLog(sprintf('Added back the %d sample records the AI made', count($records) - count($failed)));
                foreach ($failed as $result) {
                    $video->addLog("A sample record ({$result['what']}) could not be added back: {$result['error']}");
                }
            }
        } catch (Throwable $e) {
            $sandbox->delete();
            $recorder->sandbox = null;

            throw $e;
        }

        return $sandbox;
    }

    /**
     * Check the pages before any AI is spent on them, and return the ones that
     * fail. With $fail, a failing page fails the job instead.
     *
     * @return list<array{path: string, problem: string, status: ?int, url: ?string, detail: ?string}>
     */
    protected function checkPages(Recorder $recorder, Video $video, array $paths, string $message, bool $fail = true): array
    {
        if (! config('video-demo.recorder.check_pages', true) || $paths === []) {
            return [];
        }

        $this->stopIfAsked($video);
        $video->moveTo(VideoStatus::Checking, $message.' ('.count($paths).')');
        $problems = $recorder->checkPages($paths, $video->record_as, $video->deviceList()[0], fn () => $video->stopRequested());

        if ($problems !== [] && ! $fail) {
            $video->addLog(count($problems) === 1 ? "{$problems[0]['path']} failed: {$problems[0]['problem']}" : count($problems).' pages failed the check');

            return $problems;
        }

        if ($problems !== []) {
            $video->update(['issues' => $problems]);
            $first = $problems[0];

            throw new RuntimeException(count($problems) === 1
                ? "A page isn't working, so nothing was recorded: {$first['path']}: {$first['problem']}."
                : count($problems)." pages aren't working, so nothing was recorded. See the list at the top.");
        }

        $this->stopIfAsked($video);

        return [];
    }

    /**
     * Stop the job if someone pressed Stop.
     */
    protected function stopIfAsked(Video $video): void
    {
        if ($video->stopRequested()) {
            throw new VideoStopped('Stopped by you.');
        }
    }

    /**
     * Mark the video as stopped and log what the AI cost so far.
     */
    protected function stopped(Video $video): void
    {
        $video->refresh();
        $video->update(['stop_requested' => false, 'error' => null]);
        $video->moveTo(VideoStatus::Stopped, sprintf('Stopped by you. The AI cost $%.4f so far.', $video->cost));
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $e): void
    {
        $this->video->refresh();
        $this->video->stop_requested = false;
        $this->video->error = $e->getMessage();
        $this->video->moveTo(VideoStatus::Failed, 'Failed: '.$e->getMessage());
    }
}
