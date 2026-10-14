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
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Throwable;

/**
 * Makes a video: Claude writes the plan, the recorder plays it, and a failed
 * step goes back to Claude to fix. With $replan false the stored plan is
 * recorded again without asking Claude, which costs nothing.
 */
class GenerateVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public Video $video, public bool $replan = true)
    {
        $this->onConnection(config('video-demo.queue.connection'));
        $this->onQueue(config('video-demo.queue.name'));
    }

    public function handle(Planner $planner, Recorder $recorder, Encoder $encoder): void
    {
        $video = $this->video;
        $dir = Recorder::workDir($video->workPath());
        $maxRepairs = (int) config('video-demo.limits.max_repairs', 2);
        $session = null;

        if ($this->replan) {
            $video->moveTo(VideoStatus::Planning, 'Claude is reading the code');
            $session = $planner->plan($video);
            $plan = $session->plan;
            $video->addLog(sprintf('Plan ready: %d steps, about %d seconds', count($plan->steps), $plan->estimatedSeconds(Recorder::speed())));
        } else {
            $plan = Plan::fromArray($video->plan ?? []);
        }

        $devices = $video->deviceList();

        // Every version plays the same plan. A failure on any of them goes back
        // to Claude, and the corrected plan is recorded on all of them again.
        while (true) {
            $plan = $plan->fitTo(min($video->target_seconds, (int) config('video-demo.limits.max_seconds', 300)), Recorder::speed());
            $video->update(['plan' => $plan->toArray()]);

            $results = [];
            $failure = null;
            foreach ($devices as $device) {
                $video->moveTo(VideoStatus::Recording, 'Recording the '.Devices::get($device)['label'].' version');
                $result = $recorder->record($plan, "$dir/$device", $video->style ?? [], $device);
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

            if (! $session || ! $failure->canRepair() || $session->repairs >= $maxRepairs) {
                throw new \RuntimeException($failure->summary().($session ? '' : '. Regenerate to let Claude fix the plan.'));
            }

            $video->moveTo(VideoStatus::Planning, 'Asking Claude to fix the plan');
            $planner->repair($session, $video, $failure);
            $plan = $session->plan;
        }

        $video->moveTo(VideoStatus::Encoding, count($results) > 1 ? 'Encoding '.count($results).' versions' : 'Encoding the video');
        $work = Storage::disk(config('video-demo.storage.work_disk'));
        $recordings = [];
        foreach ($results as $device => $result) {
            $encoder->encode($result->video, $work->path($video->workFile($device)), $work->path($video->workFile($device, 'poster')), $result->trimSeconds, Recorder::speed());
            File::deleteDirectory("$dir/$device");
            $recordings[$device] = ['seconds' => $result->seconds, 'chapters' => $result->chapters];
        }

        // Versions no longer chosen are removed
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
        $video->moveTo(VideoStatus::Ready, sprintf('Ready. Claude cost $%.4f in total.', $video->cost));
    }

    public function failed(Throwable $e): void
    {
        $this->video->refresh();
        $this->video->error = $e->getMessage();
        $this->video->moveTo(VideoStatus::Failed, 'Failed: '.$e->getMessage());
    }
}
