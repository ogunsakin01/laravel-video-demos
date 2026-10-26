<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Ai\PlanningFailed;
use Ogunsakin01\VideoDemo\Ai\PlanningSession;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\RecordingFailed;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\Recording\Timeline;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use RuntimeException;

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

            /** @var list<array<string, mixed>> */
            public array $pageProblems = [];

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
                return array_values(array_filter($this->pageProblems, fn ($problem) => in_array($problem['path'], $paths, true)));
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

    public function test_a_start_page_the_app_doesnt_have_fails_before_planning(): void
    {
        config(['video-demo.recorder.check_pages' => true]);
        $recorder = $this->fakeApp();
        $recorder->pageProblems = [['path' => '/time-off', 'problem' => 'The page returned HTTP 404', 'status' => 404, 'url' => null, 'detail' => null]];
        $video = $this->video(['start_path' => '/time-off']);

        try {
            $this->make($video);
            $this->fail('Expected the video to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame("The start page /time-off isn't one of the app's pages: it answers 404 and no route matches it, so nothing was planned or recorded. Choose a start page the app has, or describe a flow it has.", $e->getMessage());
        }
        $this->assertSame([], $recorder->rehearsed);
        $this->assertSame('/time-off', $video->fresh()->issues[0]['path']);

        // A page with a route that answers 404 (its record isn't there yet) goes to the AI, which can add it on sample data.
        Route::get('/jobs/{job}', fn () => abort(404));
        $recorder->pageProblems = [['path' => '/jobs/5', 'problem' => 'The page returned HTTP 404', 'status' => 404, 'url' => null, 'detail' => null]];
        $this->make($second = $this->video(['start_path' => '/jobs/5']));
        $this->assertSame(VideoStatus::Ready, $second->fresh()->status);
    }

    public function test_versions_rehearsed_and_recorded_side_by_side_are_fixed_and_made_the_same_way(): void
    {
        static::$deleted = [];
        config(['video-demo.recorder.parallel_versions' => 2]);
        $this->fakeApp();
        // The browser, two versions at once: "Old button" isn't on the page; a recording leaves its video.
        $runs = [];
        Process::fake(function (PendingProcess $process) use (&$runs) {
            $dir = dirname((string) $process->command[2]);
            $job = json_decode(File::get("$dir/job.json"), true);
            $button = json_decode(File::get($job['planPath']), true)['steps'][1]['target']['name'];
            $kind = ! empty($job['dryRun']) ? 'rehearse' : 'record';
            $runs[] = "$kind $button (".basename($dir).')';
            File::put("$dir/raw.webm", 'webm');
            File::put("$dir/result.json", json_encode($button === 'Old button'
                ? ['ok' => false, 'failedStep' => 2, 'error' => 'Could not find a visible element with role "button", name "Old button".']
                : ['ok' => true, 'video' => "$dir/raw.webm", 'durationMs' => 12_000, 'chapters' => []]));

            return Process::describe()->iterations(1);
        });
        $video = $this->video();

        $this->make($video);

        // Both versions rehearsed at once, the fix rehearsed on both again, then both recorded at once.
        $this->assertSame([
            'rehearse Old button (desktop)', 'rehearse Old button (mobile)',
            'rehearse New button (desktop)', 'rehearse New button (mobile)',
            'record New button (desktop)', 'record New button (mobile)',
        ], $runs);
        $video = $video->fresh();
        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame(['desktop', 'mobile'], array_keys($video->recordings));
        $messages = collect($video->log)->pluck('message');
        $this->assertContains('Rehearsing the plan, 2 versions at a time', $messages);
        $this->assertContains('Recording 2 versions, 2 at a time', $messages);
        // Every copy of the sample database was deleted: two per rehearsal, two for recording.
        $this->assertCount(6, static::$deleted);
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

        // Rehearsals and each version's recording run on copies of the sample database, deleted
        // afterwards: what the desktop version saves (a goal set) doesn't change what mobile shows.
        $this->assertSame(array_fill(0, 5, sys_get_temp_dir().'/1-rehearsaldb1.sqlite'), $recorder->sandboxes);
        $this->assertCount(5, static::$deleted);
    }

    public function test_videos_on_the_apps_own_data_or_with_rehearsals_off_are_recorded_straight_away(): void
    {
        $recorder = $this->fakeApp();

        // On your own data a rehearsal would make every change twice.
        $video = $this->video(['sample_data' => false, 'devices' => ['desktop']]);
        $this->app->call([new GenerateVideo($video), 'handle']);
        $this->assertSame([], $recorder->rehearsed);
        $this->assertSame(['Old button (desktop)'], $recorder->recorded);
        $this->assertContains('Recording without a rehearsal first: on your own data it would make every change twice', collect($video->fresh()->log)->pluck('message'));

        // A plan recorded again as it was is rehearsed too: a step that no longer works stops
        // it in seconds, before the voice-over or any recording, saying to plan it again.
        $recorder->recorded = [];
        $video = $this->video(['devices' => ['desktop'], 'plan' => ['title' => 'x', 'summary' => '', 'steps' => [
            ['action' => 'goto', 'url' => '/jobs'],
            ['action' => 'click', 'target' => ['role' => 'button', 'name' => 'Old button']],
        ]]]);
        try {
            $this->make($video, replan: false);
            $this->fail('The rehearsal should stop it.');
        } catch (RecordingFailed) {
        }
        $this->assertSame(['Old button (desktop)'], $recorder->rehearsed);
        $this->assertSame([], $recorder->recorded);
        $this->assertStringContainsString('Try again with a new plan to let the AI fix it.', $video->fresh()->error);
        $recorder->rehearsed = [];

        config(['video-demo.recorder.rehearse' => false]);
        $recorder->recorded = [];
        $this->make($this->video(['devices' => ['desktop']]));
        $this->assertSame([], $recorder->rehearsed);
        $this->assertSame(['Old button (desktop)'], $recorder->recorded);
    }

    public function test_a_new_plan_that_fails_first_leaves_the_old_plan_its_sample_records(): void
    {
        $this->fakeApp();
        $records = [['table' => 'users', 'where' => ['id' => '@user_member'], 'data' => ['avatar' => '/images/me.jpg']]];
        $video = $this->video(['devices' => ['desktop'], 'sample_records' => $records, 'plan' => ['title' => 'x', 'summary' => '', 'steps' => [['action' => 'goto', 'url' => '/']]]]);
        // The new plan stops at once (its planner fails), as a spending limit would stop it.
        $this->app->instance(Planner::class, new class extends Planner
        {
            public function __construct() {}

            public function plan(Video $video, ?Sandbox $sandbox = null, array $problems = []): PlanningSession
            {
                throw new PlanningFailed('Stopped at $0.08, over the video\'s $0.05 limit for one attempt.');
            }
        });

        try {
            $this->make($video);
            $this->fail('The planner should stop it.');
        } catch (PlanningFailed) {
        }

        // So recording the same plan again still adds back the profile photo its pages need.
        $this->assertSame($records, $video->fresh()->sample_records);
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
                ]]), ['desktop'], $sandbox, fn () => false);
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
