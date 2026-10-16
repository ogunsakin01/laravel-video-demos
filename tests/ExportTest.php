<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Facades\VideoDemo;
use Ogunsakin01\VideoDemo\Jobs\ExportVideo;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Exporter;
use Ogunsakin01\VideoDemo\Recording\Recorder;

/**
 * GIF, square and vertical copies of a finished video. RecorderTest makes
 * real ones; these use stand-ins for the browser and ffmpeg.
 */
class ExportTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/video-demo-export-'.uniqid();
        config(['filesystems.disks.local.root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    protected function video(): Video
    {
        $video = Video::create([
            'title' => 'Post a job', 'prompt' => 'How a recruiter posts a job', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop', 'mobile'], 'version' => 2, 'style' => ['theme' => 'dark', 'accent' => '#e5693d'],
            'recordings' => ['desktop' => ['seconds' => 20, 'chapters' => [], 'focus' => [['t' => 1.5, 'x' => 100, 'y' => 200, 'w' => 300, 'h' => 40]]], 'mobile' => ['seconds' => 22, 'chapters' => []]],
        ]);
        foreach (['desktop', 'mobile'] as $device) {
            Storage::disk('local')->put($video->workFile($device), "mp4-$device");
            Storage::disk('local')->put($video->workFile($device, 'poster'), 'jpg');
        }

        return $video;
    }

    /** Stand-ins that write files the way ffmpeg and the scripts would. */
    protected function fakeTools(): object
    {
        $calls = new \ArrayObject;
        $this->app->instance(Encoder::class, new class($calls) extends Encoder
        {
            public function __construct(public \ArrayObject $calls) {}

            public function frames(string $mp4, string $dir, ?int $fps = null): void
            {
                $this->calls[] = ['frames', basename($mp4), $fps];
                File::ensureDirectoryExists($dir);
                File::put("$dir/00001.jpg", 'jpg');
            }

            public function join(string $dir, string $mp4, ?int $fps = null): void
            {
                $this->calls[] = ['join', $fps];
                File::put($mp4, 'framed-mp4');
            }

            public function copyAudio(string $from, string $mp4): void
            {
                $this->calls[] = ['audio', basename($from)];
            }
        });
        $this->app->instance(Recorder::class, new class($calls) extends Recorder
        {
            public function __construct(public \ArrayObject $calls) {}

            public function makeGif(string $framesDir, string $gif, int $fps, int $width): void
            {
                $this->calls[] = ['gif', $fps, $width];
                File::ensureDirectoryExists(dirname($gif));
                File::put($gif, 'GIF89a');
            }

            public function reframe(string $framesDir, string $outDir, array $job): void
            {
                $this->calls[] = ['reframe', $job];
                File::ensureDirectoryExists($outDir);
            }
        });

        return $calls;
    }

    public function test_the_studio_makes_and_downloads_copies(): void
    {
        $calls = $this->fakeTools();
        $video = $this->video();

        $this->get("/video-demo/studio/{$video->id}")->assertSee('Downloads')->assertSee('Vertical (9:16)')->assertSee('download="'.$video->slug.'-mobile.mp4"', false);

        Queue::fake();
        $this->post("/video-demo/studio/{$video->id}/exports", ['format' => 'vertical'])->assertRedirect();
        Queue::assertPushed(ExportVideo::class, fn ($job) => $job->format === 'vertical');
        $this->assertSame('working', $video->fresh()->exports['vertical']['status']);
        $this->getJson("/video-demo/studio/{$video->id}/status")->assertJsonPath('exports.vertical', 'working');
        $this->post("/video-demo/studio/{$video->id}/exports", ['format' => 'webm'])->assertSessionHasErrors('format');

        (new ExportVideo($video, 'vertical'))->handle(app(Exporter::class));

        $export = $video->fresh()->exports['vertical'];
        $this->assertSame(['ready', 'desktop', 2, 10], [$export['status'], $export['device'], $export['version'], $export['bytes']]);
        $this->assertSame('framed-mp4', Storage::disk('local')->get(Exporter::file($video, 'vertical')));
        $job = collect($calls)->firstWhere(0, 'reframe')[1];
        $this->assertSame([1080, 1920, 1280, 800, 'Post a job', 'dark', '#e5693d', true], [$job['width'], $job['height'], $job['srcWidth'], $job['srcHeight'], $job['title'], $job['theme'], $job['accent'], $job['follow']]);
        $this->assertSame(100, $job['focus'][0]['x']);
        $this->assertContains(['audio', 'video-desktop.mp4'], (array) $calls);

        $this->get("/video-demo/studio/{$video->id}/exports/vertical")->assertOk()->assertDownload("{$video->slug}-vertical.mp4");
        $this->get("/video-demo/studio/{$video->id}/exports/gif")->assertNotFound();
        $this->get("/video-demo/studio/{$video->id}")->assertSee('Download · 1 KB');

        // A new recording makes the copy out of date.
        $video->update(['version' => 3]);
        $this->assertFalse(Exporter::isCurrent($video->fresh(), 'vertical'));
        $this->get("/video-demo/studio/{$video->id}")->assertSee('Made from an older recording.');
    }

    public function test_copies_made_at_once_keep_each_others_state_and_start_once(): void
    {
        Queue::fake();
        $video = $this->video();

        // Pressed twice: one job.
        $this->post("/video-demo/studio/{$video->id}/exports", ['format' => 'gif'])->assertRedirect();
        $this->post("/video-demo/studio/{$video->id}/exports", ['format' => 'gif'])->assertRedirect();
        Queue::assertPushed(ExportVideo::class, 1);

        // Two workers, each with the video as it was when its job was queued.
        $square = new ExportVideo(Video::findOrFail($video->id), 'square');
        $gif = new ExportVideo(Video::findOrFail($video->id), 'gif');
        $this->fakeTools();
        $square->handle(app(Exporter::class));
        $gif->handle(app(Exporter::class));

        $exports = $video->fresh()->exports;
        $this->assertSame(['ready', 'ready'], [$exports['square']['status'], $exports['gif']['status']]);
    }

    public function test_a_failed_copy_says_why(): void
    {
        $this->fakeTools();
        $video = $this->video();

        (new ExportVideo($video, 'square', 'tv'))->handle(app(Exporter::class));

        $this->assertSame('failed', $video->fresh()->exports['square']['status']);
        $this->assertStringContainsString('no TV recording', $video->fresh()->exports['square']['error']);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('This video has no TV recording to make a copy from.');
    }

    public function test_the_facade_and_the_command_make_a_gif(): void
    {
        $calls = $this->fakeTools();
        $video = $this->video();

        $path = VideoDemo::export($video, 'gif', 'mobile');
        $this->assertSame('GIF89a', File::get($path));
        $this->assertSame(['gif', 12, 800], collect($calls)->firstWhere(0, 'gif'));
        $this->assertSame('mobile', $video->fresh()->exports['gif']['device']);

        $out = $this->root.'/docs/post-a-job.gif';
        $this->assertSame(0, Artisan::call('video-demo:export', ['slug' => $video->slug, '--out' => $out, '--json' => true]));
        $this->assertSame($out, json_decode(Artisan::output(), true)['file']);
        $this->assertFileExists($out);

        $this->assertSame(1, Artisan::call('video-demo:export', ['slug' => $video->slug, '--format' => 'webm']));
    }
}
