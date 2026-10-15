<?php

namespace Ogunsakin01\VideoDemo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Ai\Narrator;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Ai\PlanningFailed;
use Ogunsakin01\VideoDemo\Ai\Usage;
use Ogunsakin01\VideoDemo\Ai\VideoStopped;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Camera;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\RecordingFailed;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Ogunsakin01\VideoDemo\Studio\ErrorReport;
use Ogunsakin01\VideoDemo\Support\Language;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Ogunsakin01\VideoDemo\Voice\Music;
use Ogunsakin01\VideoDemo\Voice\Soundtrack;
use Ogunsakin01\VideoDemo\Voice\Speaker;
use Ogunsakin01\VideoDemo\Voice\Voices;
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

        // Queued afresh: a lock left by a worker that was killed mid-video mustn't hold this one back.
        Cache::lock($this->lockKey())->forceRelease();
    }

    /**
     * The exceptions the app threw while it was recorded on sample data.
     *
     * @var list<array<string, mixed>>
     */
    protected array $appErrors = [];

    /**
     * Execute the job.
     */
    public function handle(Planner $planner, Recorder $recorder, Encoder $encoder, Narrator $narrator, Speaker $speaker, Soundtrack $soundtrack): void
    {
        // Some queues (the database queue on SQLite, say) can hand the same
        // video to two workers at once. The second one leaves it alone.
        $lock = Cache::lock($this->lockKey(), $this->timeout + 300);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->make($planner, $recorder, $encoder, $narrator, $speaker, $soundtrack);
        } catch (VideoStopped $e) {
            throw $e;
        } catch (Throwable $e) {
            // Kept now, while what the recorder and the app saw is at hand.
            $this->video->refresh();
            $this->video->update(['error' => $e->getMessage(), 'error_details' => ErrorReport::for($this->video, $e, $this->appErrors)]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Get the name of the lock held while the video is being made.
     */
    protected function lockKey(): string
    {
        return 'video-demo:making:'.$this->video->getKey();
    }

    /**
     * Make the video: plan it, record each version, then encode them.
     */
    protected function make(Planner $planner, Recorder $recorder, Encoder $encoder, Narrator $narrator, Speaker $speaker, Soundtrack $soundtrack): void
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
        // The voice-over's clips, keyed by line; null once the voice has failed.
        $clips = $video->voice ? [] : null;
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
                // Checked first, as it finds the language of the pages, which the voice-over is spoken in.
                $canRepair = $session?->sampleData && $session->repairs < $maxRepairs;
                $problems = $this->checkPages($recorder, $video, $plan->paths(), 'Checking the pages in the plan', fail: ! $canRepair);
                if ($problems !== []) {
                    $video->moveTo(VideoStatus::Planning, 'Asking the AI to fix the data or the plan');
                    $planner->repairPages($session, $video, $problems);
                    $plan = $session->plan;

                    continue;
                }

                // A voice that can't speak the video's language would read it out wrongly.
                if ($clips !== null && ! $video->hasVoiceOver()) {
                    $video->addLog(sprintf("%s doesn't speak %s, so the video is made without a voice-over. Choose a voice that does.", Voices::label($video->voice), Language::name($video->languageCode())));
                    $clips = null;
                }
                [$plan, $clips] = $clips !== null ? $this->voiceOver($video, $plan, $narrator, $speaker) : [$plan->withoutVoice(), null];
                $plan = $plan->fitTo(min($video->target_seconds, (int) config('video-demo.limits.max_seconds', 300)), $video->playbackSpeed());
                $video->update(['plan' => $plan->toArray()]);

                $results = [];
                $failure = null;
                foreach ($devices as $device) {
                    $this->stopIfAsked($video);
                    $video->moveTo(VideoStatus::Recording, 'Recording the '.Devices::get($device)['label'].' version');
                    $result = $recorder->record($plan, "$dir/$device", $video->style ?? [], $device, $video->record_as, $shouldStop, $video->playbackSpeed(), array_map(fn ($c) => $c['seconds'], $clips ?? []));
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

                    throw new RecordingFailed("The app itself failed at {$issue['path']}: {$issue['problem']}. Fix it, then try again; asking the AI to repair the plan wouldn't help.", $failure, $this->failedStep($plan, $failure));
                }

                if (! $session || ! ($failure->canRepair() || $sampleFix) || $session->repairs >= $maxRepairs) {
                    throw new RecordingFailed(rtrim($failure->summary(), '.').($session ? '.' : '. Try again with a new plan to let the AI fix it.'), $failure, $this->failedStep($plan, $failure));
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
            $this->appErrors = $sandbox?->appErrors() ?? [];
            $sandbox?->delete();
            $recorder->sandbox = null;
        }

        $video->moveTo(VideoStatus::Encoding, count($results) > 1 ? 'Encoding '.count($results).' versions' : 'Encoding the video');
        $work = Storage::disk(config('video-demo.storage.work_disk'));
        $recordings = [];
        foreach ($results as $device => $result) {
            $mp4 = $work->path($video->workFile($device));
            $poster = $work->path($video->workFile($device, 'poster'));
            $encoder->encode($result->video, $mp4, $poster, $result->trimSeconds, $video->playbackSpeed(), $result->timeline);

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
            $this->addSound($video, $result, $clips ?? [], $mp4, $work->path($video->workFile($device, 'subtitles')), "$dir/$device", $soundtrack, $encoder);
            File::deleteDirectory("$dir/$device");
            $recordings[$device] = ['seconds' => $result->seconds, 'chapters' => $result->chapters];
        }

        // Remove the files of versions that are no longer chosen.
        foreach (array_diff($video->recordedDevices(), $devices) as $old) {
            $work->delete([$video->workFile($old), $video->workFile($old, 'poster'), $video->workFile($old, 'subtitles')]);
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
     * Get the plan with its voice-over spoken: the lines written (by the
     * narrator, when a plan has none) and each turned into a clip, whose
     * length is noted on its step. A voice that fails is logged and left
     * out, and the video is made without it.
     *
     * @return array{0: Plan, 1: ?array<string, array{file: string, seconds: float}>}
     */
    protected function voiceOver(Video $video, Plan $plan, Narrator $narrator, Speaker $speaker): array
    {
        $this->stopIfAsked($video);

        try {
            if ($plan->lines() === []) {
                $video->addLog('The AI is writing the voice-over');
                $plan = $narrator->narrate($plan, $video);
            }

            $lines = $plan->lines();

            // A voice that costs money mustn't take the video past its spending limit.
            $estimate = mb_strlen(implode(' ', $lines)) / 1000 * Voices::price($video->voice);
            if ($estimate > 0 && $video->cost + $estimate > $video->spendLimit()) {
                $video->addLog(sprintf('The voice-over was left out: at about $%.3f it would take the video past its $%.2f limit.', $estimate, $video->spendLimit()));

                return [$plan, null];
            }

            $video->addLog(sprintf('Speaking the voice-over: %d %s in %s', count($lines), count($lines) === 1 ? 'line' : 'lines', Voices::label($video->voice)));
            $clips = $speaker->speak((string) $video->voice, $lines, tone: Speaker::tone($video->style ?? []));
            if ($speaker->cost > 0) {
                $video->addUsage(new Usage(cost: $speaker->cost), 'voice');
                $video->addLog(sprintf('The voice-over cost $%.4f', $speaker->cost));
            }
        } catch (RuntimeException|PlanningFailed $e) {
            $video->addLog("The voice-over couldn't be made, so the video is made without it: ".$e->getMessage());

            return [$plan, null];
        }

        $data = $plan->toArray();
        foreach ($data['steps'] as &$step) {
            $say = trim((string) ($step['say'] ?? ''));
            if ($say !== '' && isset($clips[$say])) {
                $step['voice_ms'] = (int) round(($clips[$say]['seconds'] + 0.5) * 1000);
            }
        }

        return [Plan::fromArray($data), $clips];
    }

    /**
     * Add the sound track to a recorded version: its voice-over clips, each
     * where its step started, over the background music, and the spoken
     * lines as subtitles. If it fails, the video is kept silent.
     *
     * @param  array<string, array{file: string, seconds: float}>  $clips
     */
    protected function addSound(Video $video, RecordingResult $result, array $clips, string $mp4, string $vtt, string $dir, Soundtrack $soundtrack, Encoder $encoder): void
    {
        File::delete($vtt);
        $music = Music::find($video->music);
        if ($video->music && ! $music) {
            $video->addLog("The music [{$video->music}] wasn't found, so the video has none.");
        }

        $spoken = [];
        foreach ($result->spoken as $line) {
            if (isset($clips[$line['text']])) {
                $spoken[] = ['text' => $line['text'], 'file' => $clips[$line['text']]['file'], 'at' => $line['at'] + Soundtrack::LEAD, 'seconds' => $clips[$line['text']]['seconds']];
            }
        }
        if ($spoken === [] && ! $music) {
            return;
        }

        try {
            $soundtrack->mix(
                array_map(fn ($s) => ['file' => $s['file'], 'at' => $s['at']], $spoken),
                $music ? ['file' => Music::local((string) $video->music), 'volume' => $video->music_volume ?? (float) config('video-demo.music.volume', 0.25)] : null,
                $result->seconds,
                "$dir/sound.wav",
            );
            $encoder->addAudio($mp4, "$dir/sound.wav");
            if ($spoken !== []) {
                $soundtrack->subtitles($spoken, $vtt);
            }
        } catch (RuntimeException $e) {
            $video->addLog("The sound couldn't be added, so this version is silent: ".$e->getMessage());
        }
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
        try {
            $sandbox = Sandbox::create($video);
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage().' To record on your own local data instead, untick "Record on sample data" under "Make it again" (or set VIDEO_DEMO_SAMPLE_DATA=false).', 0, $e);
        }
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
     * Keep the language of the pages the check opened: the video is made in
     * it unless another was chosen.
     *
     * @param  array<string, string>  $languages  path => lang attribute
     * @param  list<string>  $paths
     */
    protected function notePageLanguage(Video $video, array $languages, array $paths): void
    {
        $found = null;
        foreach ($paths as $path) {
            if ($found = Language::normalize($languages[$path] ?? null)) {
                break;
            }
        }
        if ($found === null || $found === $video->page_language) {
            return;
        }

        $video->update(['page_language' => $found]);
        $made = $video->languageCode();
        $video->addLog(Language::same($found, $made)
            ? sprintf('The pages are in %s, so the video is made in %s', Language::name($found), Language::name($made))
            : sprintf('The pages are in %s; the video is made in %s, as chosen', Language::name($found), Language::name($made)));
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
        $this->notePageLanguage($video, $recorder->pageLanguages, $paths);

        if ($problems !== [] && ! $fail) {
            $video->addLog(count($problems) === 1 ? "{$problems[0]['path']} failed: {$problems[0]['problem']}" : count($problems).' pages failed the check');

            return $problems;
        }

        if ($problems !== []) {
            $video->update(['issues' => $problems]);
            $first = $problems[0];

            // Pages that only work for users with the right data may work on sample data.
            $hint = $video->sample_data ? '' : ' If it works for users with the right data (a role, a permission, a finished profile), try again on sample data.';

            throw new RuntimeException((count($problems) === 1
                ? "A page isn't working, so nothing was recorded: {$first['path']}: {$first['problem']}."
                : count($problems)." pages aren't working, so nothing was recorded. See the list at the top.").$hint);
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
     * Get the step of the plan a failed recording stopped at, as its version
     * plays it.
     *
     * @return ?array<string, mixed>
     */
    protected function failedStep(Plan $plan, RecordingResult $failure): ?array
    {
        if ($failure->failedStep === null) {
            return null;
        }
        $steps = $failure->device ? $plan->forDevice($failure->device)->steps : $plan->steps;

        return $steps[$failure->failedStep - 1] ?? null;
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
        // The same video handed to a second worker while the first makes it:
        // the queue gives up on the copy, but the video is fine.
        if ($e instanceof MaxAttemptsExceededException) {
            $probe = Cache::lock($this->lockKey(), 5);
            if (! $probe->get()) {
                return;
            }
            $probe->release();
        }

        $this->video->refresh();
        $this->video->stop_requested = false;
        $this->video->error = $e->getMessage();
        // Timeouts end the job without the chance to keep the details first.
        $this->video->error_details ??= ErrorReport::for($this->video, $e);
        $this->video->moveTo(VideoStatus::Failed, 'Failed: '.$e->getMessage());
    }
}
