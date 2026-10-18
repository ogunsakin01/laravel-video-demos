<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\Recording\Timeline;

/**
 * video-demo:preview: the published videos a change touches, recorded into a
 * folder with GIFs and a summary for a pull request, without publishing them.
 */
class PreviewTest extends TestCase
{
    protected string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().'/video-demo-preview-'.uniqid();
        $this->app->instance(Recorder::class, new class extends Recorder
        {
            public function __construct() {}

            public function record(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null, ?float $speed = null, array $voice = []): RecordingResult
            {
                if (collect($plan->steps)->contains(fn ($s) => ($s['target']['name'] ?? null) === 'Old button')) {
                    return RecordingResult::fromArray(['ok' => false, 'failedStep' => 2, 'error' => 'Could not find a visible element with role "button", name "Old button".'], $device);
                }
                File::ensureDirectoryExists($dir);
                File::put("$dir/raw.webm", 'webm');

                return RecordingResult::fromArray(['ok' => true, 'video' => "$dir/raw.webm", 'durationMs' => 12_000, 'chapters' => []], $device);
            }

            public function makeGif(string $framesDir, string $gif, int $fps, int $width): void
            {
                File::ensureDirectoryExists(dirname($gif));
                File::put($gif, 'gif');
            }
        });
        $this->app->instance(Encoder::class, new class extends Encoder
        {
            public function encode(string $webm, string $mp4, string $poster, float $trimSeconds = 0, float $speed = 1, ?Timeline $timeline = null): void
            {
                File::put($mp4, 'mp4');
                File::put($poster, 'jpg');
            }

            public function frames(string $mp4, string $dir, ?int $fps = null): void {}
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    protected function video(string $title, string $button, array $files, array $overrides = []): Video
    {
        return Video::create([
            'title' => $title, 'prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop'], 'published_at' => now(), 'version' => 3, 'source_files' => $files,
            'plan' => ['title' => $title, 'summary' => '', 'steps' => [
                ['action' => 'goto', 'url' => '/jobs'],
                ['action' => 'click', 'target' => ['role' => 'button', 'name' => $button]],
            ]],
            ...$overrides,
        ]);
    }

    public function test_the_videos_a_change_touches_are_recorded_with_a_summary_for_the_pull_request(): void
    {
        Process::fake(fn () => Process::result("resources/views/jobs.blade.php\nresources/views/close.blade.php\n"));
        $works = $this->video('Post a job', 'Post job', ['resources/views/jobs.blade.php']);
        $this->video('Close a job', 'Old button', ['resources/views/close.blade.php']);
        $this->video('Change your password', 'Save', ['resources/views/profile.blade.php']);

        $this->assertSame(1, Artisan::call('video-demo:preview', ['--changed' => 'abc123', '--out' => $this->out, '--json' => true]));
        $json = json_decode(Artisan::output(), true);

        // Only the videos made from the changed code; one still works, one doesn't.
        $this->assertSame(['close-a-job', 'post-a-job'], array_column($json['videos'], 'slug'));
        $this->assertFileExists("$this->out/post-a-job.mp4");
        $this->assertFileExists("$this->out/post-a-job.gif");
        // Only the previews and the summary: no working files or folders.
        $this->assertSame(['post-a-job.gif', 'post-a-job.jpg', 'post-a-job.mp4', 'summary.md'], collect(File::files($this->out))->map->getFilename()->sort()->values()->all());
        $this->assertSame([], File::directories($this->out));
        $summary = File::get("$this->out/summary.md");
        $this->assertStringContainsString('1 of 2 no longer works', $summary);
        $this->assertStringContainsString('| Post a job | Still works (12 seconds) | `post-a-job.mp4`, `post-a-job.gif` |', $summary);
        $this->assertStringContainsString('| Close a job | Broken at step 2: Could not find a visible element', $summary);

        // Nothing about the video changed, and nothing was published again.
        $this->assertSame([3, VideoStatus::Ready], [$works->fresh()->version, $works->fresh()->status]);
    }

    public function test_named_videos_without_gifs_and_nothing_to_do(): void
    {
        $this->video('Post a job', 'Post job', []);

        $this->assertSame(0, Artisan::call('video-demo:preview', ['slug' => ['post-a-job'], '--out' => $this->out, '--no-gif' => true]));
        $this->assertFileExists("$this->out/post-a-job.mp4");
        $this->assertFileDoesNotExist("$this->out/post-a-job.gif");

        Process::fake(fn () => Process::result(''));
        $this->artisan('video-demo:preview', ['--changed' => 'abc123', '--out' => $this->out])->expectsOutputToContain('No published video was made from the changed code.')->assertSuccessful();
        $this->assertStringContainsString('touches none of the published how-to videos', File::get("$this->out/summary.md"));
        // Named videos aren't "touched by this change".
        Artisan::call('video-demo:preview', ['slug' => ['post-a-job'], '--out' => $this->out]);
        $this->assertStringStartsWith("## How-to videos\n", File::get("$this->out/summary.md"));

        config(['video-demo.studio.enabled' => false]);
        $this->artisan('video-demo:preview', ['--out' => $this->out])->expectsOutputToContain('The studio is disabled here')->assertFailed();
    }
}
