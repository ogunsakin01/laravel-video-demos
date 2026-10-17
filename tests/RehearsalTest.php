<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Closure;
use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Ai\PlanningSession;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\Recording\Timeline;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;

/**
 * Before a plan is recorded it is played once without recording, on a copy
 * of the sample database, so a step that doesn't work goes back to the AI
 * first, rather than after a wasted recording.
 */
class RehearsalTest extends TestCase
{
    /**
     * A browser where clicking "Old button" fails, a planner whose repair
     * clicks "New button" instead, and an encoder that makes nothing.
     */
    protected function fakeApp(): object
    {
        $recorder = new class extends Recorder
        {
            public array $rehearsed = [];

            public array $recorded = [];

            public array $sandboxes = [];

            public function __construct() {}

            public function verify(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null): RecordingResult
            {
                $button = $plan->steps[1]['target']['name'];
                $this->rehearsed[] = "$button ($device)";
                $this->sandboxes[] = $this->sandbox?->path;

                return $button === 'Old button'
                    ? RecordingResult::fromArray(['ok' => false, 'failedStep' => 2, 'error' => 'Could not find a visible element with role "button", name "Old button".'], $device)
                    : RecordingResult::fromArray(['ok' => true], $device);
            }

            public function record(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null, ?float $speed = null, array $voice = []): RecordingResult
            {
                $this->recorded[] = $plan->steps[1]['target']['name']." ($device)";
                $this->sandboxes[] = $this->sandbox?->path;
                File::ensureDirectoryExists($dir);
                File::put("$dir/raw.webm", 'webm');

                return RecordingResult::fromArray(['ok' => true, 'video' => "$dir/raw.webm", 'durationMs' => 12_000, 'chapters' => []], $device);
            }

            public function checkPages(array $paths, ?string $recordAs = null, string $device = 'desktop', ?Closure $shouldStop = null): array
            {
                return [];
            }
        };
        $this->app->instance(Recorder::class, $recorder);
        $this->app->instance(Encoder::class, new class extends Encoder
        {
            public function encode(string $webm, string $mp4, string $poster, float $trimSeconds = 0, float $speed = 1, ?Timeline $timeline = null): void
            {
                File::put($mp4, 'mp4');
                File::put($poster, 'jpg');
            }
        });
        $this->app->instance(Planner::class, new class extends Planner
        {
            public function __construct() {}

            protected function steps(string $button): Plan
            {
                return Plan::fromArray(['title' => 'Post a job', 'summary' => '', 'steps' => [
                    ['action' => 'goto', 'url' => '/jobs'],
                    ['action' => 'click', 'target' => ['role' => 'button', 'name' => $button]],
                ]]);
            }

            public function plan(Video $video, ?Sandbox $sandbox = null, array $problems = []): PlanningSession
            {
                $session = new PlanningSession;
                $session->plan = $this->steps('Old button');

                return $session;
            }

            public function repair(PlanningSession $session, Video $video, RecordingResult $failure): PlanningSession
            {
                $session->repairs++;
                $session->plan = $this->steps('New button');

                return $session;
            }
        });

        return $recorder;
    }

    /**
     * Make the video, on a sample database that is a plain file here.
     */
    protected function make(Video $video, bool $replan = true): void
    {
        $job = new class($video, $replan) extends GenerateVideo
        {
            protected function sampleDatabase(Video $video, Recorder $recorder): Sandbox
            {
                return new class(sys_get_temp_dir().'/1-recordingdb12.sqlite') extends Sandbox
                {
                    public function copy(): Sandbox
                    {
                        return new class(sys_get_temp_dir().'/1-rehearsaldb1.sqlite') extends Sandbox
                        {
                            public function delete(): void
                            {
                                RehearsalTest::$deleted[] = $this->path;
                            }

                            public function appErrors(int $limit = 20): array
                            {
                                return [];
                            }
                        };
                    }

                    public function delete(): void {}

                    public function appErrors(int $limit = 20): array
                    {
                        return [];
                    }
                };
            }
        };
        $this->app->call([$job, 'handle']);
    }

    /** @var list<string> */
    public static array $deleted = [];

    protected function video(array $overrides = []): Video
    {
        return Video::create([
            'title' => 'Post a job', 'prompt' => 'How to post a job', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Queued, 'devices' => ['desktop', 'mobile'], 'sample_data' => true, ...$overrides,
        ]);
    }

    public function test_a_step_that_fails_in_rehearsal_is_fixed_before_anything_is_recorded(): void
    {
        static::$deleted = [];
        $recorder = $this->fakeApp();
        $video = $this->video();

        $this->make($video);

        // The broken plan was only rehearsed; the fixed one rehearsed on each version, then recorded.
        $this->assertSame(['Old button (desktop)', 'New button (desktop)', 'New button (mobile)'], $recorder->rehearsed);
        $this->assertSame(['New button (desktop)', 'New button (mobile)'], $recorder->recorded);
        $this->assertSame(VideoStatus::Ready, $video->fresh()->status);
        $messages = collect($video->fresh()->log)->pluck('message');
        $this->assertContains('Rehearsing the plan', $messages);
        $this->assertContains('Rehearsal: Step 2 failed (Desktop): Could not find a visible element with role "button", name "Old button".', $messages);

        // Rehearsals run on copies of the sample database, deleted afterwards; the recording on the original.
        $this->assertSame([...array_fill(0, 3, sys_get_temp_dir().'/1-rehearsaldb1.sqlite'), ...array_fill(0, 2, sys_get_temp_dir().'/1-recordingdb12.sqlite')], $recorder->sandboxes);
        $this->assertCount(3, static::$deleted);
    }

    public function test_videos_on_the_apps_own_data_recorded_as_they_were_or_with_rehearsals_off_are_recorded_straight_away(): void
    {
        $recorder = $this->fakeApp();

        // On your own data a rehearsal would make every change twice.
        $video = $this->video(['sample_data' => false, 'devices' => ['desktop']]);
        $this->app->call([new GenerateVideo($video), 'handle']);
        $this->assertSame([], $recorder->rehearsed);
        $this->assertSame(['Old button (desktop)'], $recorder->recorded);
        $this->assertContains('Recording without a rehearsal first: on your own data it would make every change twice', collect($video->fresh()->log)->pluck('message'));

        // A plan recorded again as it was, which no AI can fix, is recorded as before.
        $recorder->recorded = [];
        $video = $this->video(['devices' => ['desktop'], 'plan' => ['title' => 'x', 'summary' => '', 'steps' => [
            ['action' => 'goto', 'url' => '/jobs'],
            ['action' => 'click', 'target' => ['role' => 'button', 'name' => 'Old button']],
        ]]]);
        $this->make($video, replan: false);
        $this->assertSame([], $recorder->rehearsed);
        $this->assertSame(['Old button (desktop)'], $recorder->recorded);

        config(['video-demo.recorder.rehearse' => false]);
        $recorder->recorded = [];
        $this->make($this->video(['devices' => ['desktop']]));
        $this->assertSame([], $recorder->rehearsed);
        $this->assertSame(['Old button (desktop)'], $recorder->recorded);
    }

    public function test_a_failed_rehearsal_keeps_its_screenshot_for_the_full_error_view(): void
    {
        $recorder = new class extends Recorder
        {
            public function __construct() {}

            public function verify(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null): RecordingResult
            {
                File::ensureDirectoryExists($dir);
                File::put("$dir/failure.jpg", 'jpg');

                return RecordingResult::fromArray(['ok' => false, 'failedStep' => 2, 'error' => 'Could not find it.', 'screenshot' => "$dir/failure.jpg"], $device);
            }
        };
        $video = $this->video(['devices' => ['desktop']]);
        $job = new class($video) extends GenerateVideo
        {
            public function rehearseFor(Recorder $recorder, Video $video, Sandbox $sandbox): ?RecordingResult
            {
                return $this->rehearse($recorder, $video, Plan::fromArray(['title' => 'x', 'summary' => '', 'steps' => [
                    ['action' => 'goto', 'url' => '/jobs'],
                    ['action' => 'click', 'target' => ['role' => 'button', 'name' => 'Old button']],
                ]]), ['desktop'], $sandbox, fn () => false, canRepair: true);
            }
        };
        $sandbox = new class(sys_get_temp_dir().'/1-recordingdb12.sqlite') extends Sandbox
        {
            public function copy(): Sandbox
            {
                return new class(sys_get_temp_dir().'/1-rehearsaldb1.sqlite') extends Sandbox
                {
                    public function delete(): void {}

                    public function appErrors(int $limit = 20): array
                    {
                        return [];
                    }
                };
            }
        };

        $failure = $job->rehearseFor($recorder, $video, $sandbox);
        $this->assertSame(2, $failure->failedStep);
        $this->assertFileExists($failure->screenshot);
        File::deleteDirectory(Recorder::workDir($video->workPath()));
    }
}
