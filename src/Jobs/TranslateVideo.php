<?php

namespace Ogunsakin01\VideoDemo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Ai\Translator;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Ogunsakin01\VideoDemo\Support\Language;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use RuntimeException;
use Throwable;

/**
 * Makes a video in another language from the original's plan: the AI
 * translates its words, the steps are checked with the app in that language
 * (finding again any button whose label the app translates), and the plan is
 * recorded, with no new plan.
 */
class TranslateVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The most steps fixed after the app's own labels turned out translated.
     */
    public const MAX_FIXES = 4;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out: translating
     * and checking the steps. The recording is a job of its own.
     */
    public int $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(public Video $video)
    {
        $this->onConnection(VideoDemoServiceProvider::queueConnection());
        $this->onQueue(VideoDemoServiceProvider::queueName());
    }

    /**
     * Execute the job.
     */
    public function handle(Translator $translator, Recorder $recorder): void
    {
        $video = $this->video->fresh() ?? $this->video;
        if ($video->status === VideoStatus::Stopped || $video->stop_requested) {
            $video->update(['stop_requested' => false]);
            $video->moveTo(VideoStatus::Stopped, 'Stopped by you.');

            return;
        }

        try {
            $language = Language::name($video->languageCode());
            $from = $video->source?->languageCode() ?? Language::app();
            if ($video->cost >= $video->spendLimit()) {
                throw new RuntimeException(sprintf('The video has reached its $%.2f spending limit.', $video->spendLimit()));
            }

            $video->moveTo(VideoStatus::Planning, "The AI is translating the words into $language");
            $plan = $translator->translate(Plan::fromArray($video->plan ?? []), $video, $from);
            $video->update(['plan' => $plan->toArray()]);
            $video->addLog(sprintf('Translated into %s for $%.4f', $language, $video->cost));

            $plan = $this->checkSteps($video, $plan, $translator, $recorder);
            $video->update(['plan' => $plan->toArray()]);
        } catch (Throwable $e) {
            $video->refresh();
            $video->update(['error' => $e->getMessage()]);
            $video->moveTo(VideoStatus::Failed, 'Failed: '.$e->getMessage());

            return;
        }

        // On a queue the recording is a job of its own, with the time a
        // recording of its length and versions needs; run directly (queue:
        // false), it runs straight after.
        if ($this->job === null || $this->job instanceof SyncJob) {
            try {
                GenerateVideo::dispatchSync($video->fresh(), replan: false);
            } catch (Throwable) {
                // The job saves the error on the video.
            }

            return;
        }

        $video->moveTo(VideoStatus::Queued, "Queued to record the $language version");
        GenerateVideo::dispatch($video->fresh(), replan: false);
    }

    /**
     * Play the translated plan with the app in the video's language, on
     * each version, and fix a step whose element isn't found: the app's own
     * labels may be translated too. Costs no AI unless a step needs fixing.
     */
    protected function checkSteps(Video $video, Plan $plan, Translator $translator, Recorder $recorder): Plan
    {
        $language = Language::name($video->languageCode());
        $sandbox = null;
        $recorder->locale = $video->recordingLocale();

        try {
            if ($video->sample_data) {
                $video->moveTo(VideoStatus::Checking, 'Copying the sample database');
                $sandbox = Sandbox::prepareFor($video, true, $video->addLog(...));
                $recorder->sandbox = $sandbox;
            }

            for ($fixes = 0; ; $fixes++) {
                $failure = null;
                foreach ($video->deviceList() as $device) {
                    $video->moveTo(VideoStatus::Checking, "Checking the steps with the app in $language (".Devices::get($device)['label'].')');
                    $dir = Recorder::workDir($video->workPath("translate/$device"));
                    try {
                        $run = $recorder->verify($plan, $dir, $video->style ?? [], $device, $video->record_as);
                    } finally {
                        File::deleteDirectory($dir);
                    }
                    if (! $run->ok) {
                        $failure = $run;

                        break;
                    }
                }

                if (! $failure) {
                    return $plan;
                }
                if (! $failure->canRepair() || $fixes >= self::MAX_FIXES) {
                    throw new RuntimeException(rtrim($failure->summary(), '.').". With the app in $language, the plan doesn't work as it is. Open the video, edit the plan, then record it again.");
                }

                $video->moveTo(VideoStatus::Planning, "Step {$failure->failedStep} wasn't found with the app in $language; the AI is finding it");
                $plan = $translator->fixStep($plan, $video, $failure);
                $video->update(['plan' => $plan->toArray()]);
            }
        } finally {
            $sandbox?->delete();
            $recorder->sandbox = null;
            $recorder->locale = null;
        }
    }

    /**
     * Handle a job that failed outside handle(), such as a timeout.
     */
    public function failed(?Throwable $e): void
    {
        $video = $this->video->fresh();
        if ($video && $video->status->isWorking()) {
            $video->update(['error' => $e?->getMessage() ?: 'It stopped before it finished.', 'stop_requested' => false]);
            $video->moveTo(VideoStatus::Failed, 'Failed: '.($e?->getMessage() ?: 'it stopped before it finished.'));
        }
    }
}
