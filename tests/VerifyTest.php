<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Events\VideoBroke;
use Ogunsakin01\VideoDemo\Facades\VideoDemo;
use Ogunsakin01\VideoDemo\Jobs\VerifyVideo;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\Recording\Verifier;

/**
 * Checking that videos still work after the app changed: video-demo:verify,
 * VideoDemo::verify() and the studio's "Does it still work?".
 */
class VerifyTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/video-demo-verify-'.uniqid();
        config(['filesystems.disks.local.root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    protected function video(string $title, array $overrides = []): Video
    {
        return Video::create([
            'title' => $title, 'prompt' => "How to $title", 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop', 'mobile'], 'recordings' => ['desktop' => ['seconds' => 20, 'chapters' => []], 'mobile' => ['seconds' => 22, 'chapters' => []]],
            'published_at' => now(), 'version' => 1,
            'plan' => ['title' => $title, 'summary' => '', 'steps' => [
                ['action' => 'goto', 'url' => '/jobs'],
                ['action' => 'click', 'target' => ['role' => 'button', 'name' => 'New job']],
                ['action' => 'caption', 'title' => 'Fill it in'],
            ]],
            ...$overrides,
        ]);
    }

    /**
     * A recorder whose checks pass, except for the videos and devices
     * $fails says, where step 2 can't find its button.
     *
     * @param  Closure(Plan, string): bool  $fails
     */
    protected function fakeRecorder(Closure $fails): object
    {
        $recorder = new class($fails) extends Recorder
        {
            public array $checked = [];

            public function __construct(public Closure $fails) {}

            public function verify(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null): RecordingResult
            {
                $this->checked[] = [$plan->title, $device];
                if (! ($this->fails)($plan, $device)) {
                    return RecordingResult::fromArray(['ok' => true], $device);
                }
                File::ensureDirectoryExists($dir);
                File::put("$dir/failure.jpg", 'jpg');

                return RecordingResult::fromArray([
                    'ok' => false, 'failedStep' => 2, 'error' => 'Could not find a visible element with role "button", name "New job".',
                    'url' => 'http://app.test/jobs', 'screenshot' => "$dir/failure.jpg",
                ], $device);
            }

            public function checkPages(array $paths, ?string $recordAs = null, string $device = 'desktop', ?Closure $shouldStop = null): array
            {
                return [];
            }
        };
        $this->app->instance(Recorder::class, $recorder);

        return $recorder;
    }

    public function test_verify_checks_published_videos_on_each_version_and_fails_on_a_broken_one(): void
    {
        Event::fake([VideoBroke::class]);
        $recorder = $this->fakeRecorder(fn (Plan $plan, string $device) => $plan->title === 'Post a job' && $device === 'mobile');
        $this->video('Close a job');
        $broken = $this->video('Post a job');
        $this->video('Draft', ['published_at' => null]);

        $this->artisan('video-demo:verify', ['--json' => true])->assertExitCode(1);

        // Published videos only, every version until one fails.
        $this->assertSame([['Close a job', 'desktop'], ['Close a job', 'mobile'], ['Post a job', 'desktop'], ['Post a job', 'mobile']], $recorder->checked);

        $result = $broken->fresh()->verify_result;
        $this->assertFalse($result['ok']);
        $this->assertSame(['mobile', 2, 'click'], [$result['device'], $result['step'], $result['action']]);
        $this->assertStringContainsString('New job', $result['error']);
        $this->assertTrue($broken->fresh()->isBroken());
        $this->assertNotNull($broken->fresh()->verified_at);
        // The page as it was is kept for the studio.
        $this->assertTrue(Storage::disk('local')->exists($broken->workPath('verify.jpg')));
        Event::assertDispatched(VideoBroke::class, fn (VideoBroke $e) => $e->video->is($broken) && $e->result['step'] === 2);
        Event::assertDispatchedTimes(VideoBroke::class, 1);

        // Nothing about the video itself changed: it is still ready and published.
        $this->assertSame(VideoStatus::Ready, $broken->fresh()->status);
        $this->assertNotNull($broken->fresh()->published_at);
    }

    public function test_verify_prints_json_for_ci(): void
    {
        $this->fakeRecorder(fn (Plan $plan) => $plan->title === 'Post a job');
        $this->video('Post a job');
        $this->video('Close a job');

        $this->assertSame(1, Artisan::call('video-demo:verify', ['--json' => true]));
        $json = json_decode(Artisan::output(), true);
        $this->assertSame([false, 2, 1, 0], [$json['ok'], $json['checked'], $json['broken'], $json['skipped']]);
        $this->assertSame(['close-a-job' => 'ok', 'post-a-job' => 'broken'], array_column($json['videos'], 'status', 'slug'));
        $broken = collect($json['videos'])->firstWhere('slug', 'post-a-job');
        $this->assertSame([2, 'click', 'desktop'], [$broken['step'], $broken['action'], $broken['device']]);
        $this->assertStringContainsString('/video-demo/studio/', $broken['studio_url']);

        $this->artisan('video-demo:verify', ['slug' => ['close-a-job']])
            ->expectsOutputToContain('1 checked, 0 broken.')
            ->assertExitCode(0);
    }

    public function test_changed_checks_only_the_videos_made_from_changed_code(): void
    {
        $recorder = $this->fakeRecorder(fn () => false);
        Process::fake(fn () => Process::result("app/Http/Controllers/JobController.php\nREADME.md\n"));
        $this->video('Post a job', ['source_files' => ['app/Http/Controllers/JobController.php', 'resources/views/jobs/create.blade.php']]);
        $this->video('Change your password', ['source_files' => ['resources/views/profile.blade.php']]);
        // Made before files were noted: any change may affect it.
        $this->video('Close a job', ['source_files' => null]);

        $this->assertSame(0, Artisan::call('video-demo:verify', ['--changed' => 'origin/main', '--json' => true]));
        $json = json_decode(Artisan::output(), true);
        $this->assertSame([2, 1], [$json['checked'], $json['skipped']]);
        $this->assertSame('None of its code changed.', collect($json['videos'])->firstWhere('slug', 'change-your-password')['reason']);
        $this->assertSame(['app/Http/Controllers/JobController.php'], collect($json['videos'])->firstWhere('slug', 'post-a-job')['changed']);

        $this->assertSame(['Close a job', 'Post a job'], array_values(array_unique(array_column($recorder->checked, 0))));
        Process::assertRan(fn ($process) => $process->command === ['git', 'diff', '--name-only', 'origin/main', '--']);

        $this->artisan('video-demo:verify', ['--changed' => '--output=x'])->assertExitCode(1);
    }

    public function test_a_videos_files_include_the_controllers_behind_its_pages(): void
    {
        // A controller in the app, as Routes::files() only reports files inside it.
        $file = base_path('app/Http/Controllers/VerifyJobsController.php');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass VerifyJobsController\n{\n    public function index()\n    {\n        return 'jobs';\n    }\n}\n");
        try {
            require_once $file;
            Route::get('/jobs', ['App\\Http\\Controllers\\VerifyJobsController', 'index'])->name('jobs.index');
            Route::getRoutes()->refreshNameLookups();
            $video = $this->video('Post a job', ['source_files' => ['resources/views/jobs/index.blade.php']]);

            $this->assertSame(['app/Http/Controllers/VerifyJobsController.php', 'resources/views/jobs/index.blade.php'], Verifier::sourceFiles($video));
            $this->assertSame([], Verifier::touched($video, ['README.md']));
            $this->assertSame(['app/Http/Controllers/VerifyJobsController.php'], Verifier::touched($video, ['app/Http/Controllers/VerifyJobsController.php']));

            // A video planned before files were noted is matched on its pages' files,
            // so it isn't checked (or recorded on deploy) after every change.
            $old = $this->video('Close a job', ['source_files' => null]);
            $this->assertSame([], Verifier::touched($old, ['README.md']));
            $this->assertSame(['app/Http/Controllers/VerifyJobsController.php'], Verifier::touched($old, ['app/Http/Controllers/VerifyJobsController.php']));
            // With no files known at all, any change may affect it.
            $this->assertNull(Verifier::touched($this->video('Elsewhere', ['source_files' => null, 'plan' => ['title' => 'x', 'summary' => '', 'steps' => [['action' => 'goto', 'url' => '/nowhere']]]]), ['README.md']));
        } finally {
            File::delete($file);
        }
    }

    public function test_the_facade_and_the_studio_check_a_video(): void
    {
        $this->fakeRecorder(fn () => true);
        $video = $this->video('Post a job');

        $result = VideoDemo::verify($video);
        $this->assertFalse($result['ok']);
        $this->assertSame('desktop', $result['device']);

        $this->get("/video-demo/studio/{$video->id}")
            ->assertSee('Does it still work?')
            ->assertSee('Step 2 (click) no longer works on Desktop')
            ->assertSee('Fix with a new plan');
        $this->get("/video-demo/studio/{$video->id}/verify.jpg")->assertOk();
        $this->get('/video-demo/studio')->assertSee('Broken');

        Queue::fake();
        $this->post("/video-demo/studio/{$video->id}/verify")->assertRedirect();
        Queue::assertPushed(VerifyVideo::class);
        $this->assertSame('working', $video->fresh()->verify_result['status']);
        $this->getJson("/video-demo/studio/{$video->id}/status")->assertJsonPath('verify', 'working');
        $this->get("/video-demo/studio/{$video->id}")->assertSee('Opening the video’s pages and playing its steps.');
    }

    public function test_a_video_without_a_plan_or_being_made_is_not_checked(): void
    {
        $this->fakeRecorder(fn () => false);
        $this->video('Draft', ['plan' => null]);
        $working = $this->video('Recording now', ['status' => VideoStatus::Recording]);

        $this->artisan('video-demo:verify', ['--all' => true])
            ->expectsOutputToContain('No videos to check.')
            ->assertExitCode(0);

        $this->expectException(\RuntimeException::class);
        VideoDemo::verify($working);
    }

    public function test_a_page_that_fails_to_open_breaks_the_video_before_its_steps_are_played(): void
    {
        $recorder = new class extends Recorder
        {
            public bool $played = false;

            public function verify(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null): RecordingResult
            {
                $this->played = true;

                return RecordingResult::fromArray(['ok' => true], $device);
            }

            public function checkPages(array $paths, ?string $recordAs = null, string $device = 'desktop', ?Closure $shouldStop = null): array
            {
                return [['path' => '/jobs', 'problem' => 'HTTP 500', 'status' => 500, 'url' => 'http://app.test/jobs', 'detail' => 'Undefined variable $jobs']];
            }
        };
        $this->app->instance(Recorder::class, $recorder);
        config(['video-demo.recorder.check_pages' => true]);
        $video = $this->video('Post a job');

        $result = app(Verifier::class)->verify($video);

        $this->assertFalse($result['ok']);
        $this->assertFalse($recorder->played);
        $this->assertSame('/jobs: HTTP 500.', $result['error']);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('Undefined variable $jobs');
    }
}
