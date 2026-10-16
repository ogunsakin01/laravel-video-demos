<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Ai\PlanningSession;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\Recording\Timeline;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;

/**
 * Recording published videos again after the app changed, as a deploy
 * pipeline does: video-demo:refresh, with --changed and --fix.
 */
class RefreshTest extends TestCase
{
    protected function video(string $title, string $button, array $files): Video
    {
        return Video::create([
            'title' => $title, 'prompt' => "How to $title", 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop'], 'published_at' => now(), 'version' => 1, 'source_files' => $files,
            'plan' => ['title' => $title, 'summary' => '', 'steps' => [
                ['action' => 'goto', 'url' => '/jobs'],
                ['action' => 'click', 'target' => ['role' => 'button', 'name' => $button]],
            ]],
        ]);
    }

    /**
     * A browser where a plan works unless it clicks "Old button", and a
     * planner whose new plan clicks "New button" instead.
     */
    protected function fakeApp(): object
    {
        $recorder = new class extends Recorder
        {
            public array $recorded = [];

            public function __construct() {}

            public function record(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null, ?float $speed = null, array $voice = []): RecordingResult
            {
                $this->recorded[] = $plan->title;
                if (collect($plan->steps)->contains(fn ($s) => ($s['target']['name'] ?? null) === 'Old button')) {
                    return RecordingResult::fromArray(['ok' => false, 'failedStep' => 2, 'error' => 'Could not find a visible element with role "button", name "Old button".'], $device);
                }
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

            public function plan(Video $video, ?Sandbox $sandbox = null, array $problems = []): PlanningSession
            {
                $session = new PlanningSession;
                $session->plan = Plan::fromArray(['title' => $video->title, 'summary' => '', 'steps' => [
                    ['action' => 'goto', 'url' => '/jobs'],
                    ['action' => 'click', 'target' => ['role' => 'button', 'name' => 'New button']],
                ]]);

                return $session;
            }
        });

        return $recorder;
    }

    public function test_changed_records_again_only_the_videos_made_from_changed_code(): void
    {
        $recorder = $this->fakeApp();
        Process::fake(fn () => Process::result("resources/views/jobs.blade.php\n"));
        $jobs = $this->video('Post a job', 'Post job', ['resources/views/jobs.blade.php']);
        $this->video('Change your password', 'Save', ['resources/views/profile.blade.php']);
        // Its code isn't known: not recorded on every change.
        $this->video('Close a job', 'Close', [])->update(['source_files' => null]);
        app(Manifest::class)->put($this->manifestVideo(['slug' => $jobs->slug, 'version' => 1]));

        $this->assertSame(0, Artisan::call('video-demo:refresh', ['--changed' => 'abc123', '--json' => true]));
        $json = json_decode(Artisan::output(), true);

        $this->assertSame([true, 1, 0, 2], [$json['ok'], $json['refreshed'], $json['failed'], $json['skipped']]);
        $this->assertStringContainsString("The code it was made from isn't known.", collect($json['videos'])->firstWhere('slug', 'close-a-job')['reason']);
        $this->assertSame(['Post a job'], $recorder->recorded);
        $this->assertSame('None of its code changed.', collect($json['videos'])->firstWhere('slug', 'change-your-password')['reason']);
        // Recorded again from its plan, at no AI cost, and published again.
        $jobs->refresh();
        $this->assertSame([VideoStatus::Ready, 2, 0.0], [$jobs->status, $jobs->version, $jobs->cost]);
        $this->assertSame(2, app(Manifest::class)->find($jobs->slug, 'private')['version']);
        Process::assertRan(fn ($process) => $process->command === ['git', 'diff', '--name-only', 'abc123', '--']);
    }

    public function test_a_plan_that_no_longer_works_fails_or_with_fix_gets_a_new_one(): void
    {
        $recorder = $this->fakeApp();
        $broken = $this->video('Post a job', 'Old button', ['resources/views/jobs.blade.php']);

        // Without --fix it fails, saying how to fix it.
        $this->artisan('video-demo:refresh')
            ->expectsOutputToContain('Run again with --fix')
            ->assertExitCode(1);
        $this->assertSame(VideoStatus::Failed, $broken->fresh()->status);

        // With --fix the AI plans it again, and it is recorded and published.
        $this->assertSame(0, Artisan::call('video-demo:refresh', ['--fix' => true, '--json' => true]));
        $json = json_decode(Artisan::output(), true);
        $this->assertSame('fixed', $json['videos'][0]['status']);
        $this->assertSame(VideoStatus::Ready, $broken->fresh()->status);
        $this->assertSame('New button', $broken->fresh()->plan['steps'][1]['target']['name']);
        $this->assertSame(['Post a job', 'Post a job', 'Post a job'], $recorder->recorded);
    }

    public function test_refresh_needs_the_studio_and_a_real_git_ref(): void
    {
        $this->artisan('video-demo:refresh', ['--changed' => '--output=x'])->assertExitCode(1);

        config(['video-demo.studio.enabled' => false]);
        $this->artisan('video-demo:refresh')->expectsOutputToContain('The studio is disabled here')->assertExitCode(1);
    }
}
