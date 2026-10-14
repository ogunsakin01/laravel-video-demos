<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Events\VideoStatusChanged;
use Ogunsakin01\VideoDemo\Facades\VideoDemo;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Camera;
use Ogunsakin01\VideoDemo\Support\Favicon;

/** The facade for apps that build their own screens, the status event, cleaning up and the favicon. */
class ApiTest extends TestCase
{
    public function test_the_facade_makes_and_manages_videos(): void
    {
        Queue::fake();

        $video = VideoDemo::make('How a recruiter posts a job', [
            'title' => 'Post a job', 'length' => 45, 'style' => 'spotlight', 'devices' => ['desktop', 'mobile'],
            'max_cost' => 0.5, 'max_steps' => 15, 'speed' => 1.5, 'as' => 'guest',
        ]);

        $this->assertSame(VideoStatus::Queued, $video->status);
        $this->assertSame(['Post a job', 45, 'Spotlight', 1.5, 'guest'], [$video->title, $video->target_seconds, $video->style['name'], $video->playbackSpeed(), $video->record_as]);
        $this->assertTrue($video->style['zoom']);
        Queue::assertPushed(GenerateVideo::class, fn ($job) => $job->video->is($video) && $job->replan);

        $this->assertTrue(VideoDemo::find($video->id)->is($video));
        $this->assertTrue(VideoDemo::find('post-a-job')->is($video));
        $this->assertCount(1, VideoDemo::videos());
        $this->assertContains('spotlight', VideoDemo::styles()->pluck('key'));
        $this->assertArrayHasKey('guest', VideoDemo::userTypes());
        $this->assertArrayHasKey('claude-sonnet-5-5', VideoDemo::models());
        $this->assertGreaterThan(0, VideoDemo::estimate('claude-sonnet-5-5', 'medium', 60)['high']);

        VideoDemo::stop($video);
        $this->assertSame(VideoStatus::Stopped, $video->fresh()->status);

        // Recording the same plan again, slower, costs nothing.
        $video->update(['plan' => ['title' => 'x', 'summary' => '', 'steps' => [['action' => 'goto', 'url' => '/']]]]);
        VideoDemo::rerecord($video->fresh(), speed: 1.0);
        $this->assertSame(1.0, $video->fresh()->playbackSpeed());
        Queue::assertPushed(GenerateVideo::class, fn ($job) => ! $job->replan);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown style [cinema]');
        VideoDemo::make('x', ['style' => 'cinema']);
    }

    public function test_the_facade_reads_the_library(): void
    {
        Storage::fake('public');
        app(Manifest::class)->put($this->manifestVideo());

        $this->assertSame('post-a-job', VideoDemo::library()[0]['slug']);
        $this->assertSame('post-a-job', VideoDemo::search('details')[0]['slug']);
        $this->assertStringEndsWith('/video-demo/post-a-job-v1.mp4', VideoDemo::url(VideoDemo::library()[0]));
    }

    public function test_status_changes_fire_an_event(): void
    {
        Event::fake([VideoStatusChanged::class]);
        $video = Video::create(['prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'status' => VideoStatus::Planning]);

        $video->moveTo(VideoStatus::Ready, 'Ready.');
        $video->moveTo(VideoStatus::Ready);

        Event::assertDispatchedTimes(VideoStatusChanged::class, 1);
        Event::assertDispatched(VideoStatusChanged::class, fn ($e) => $e->video->is($video) && $e->from === VideoStatus::Planning && $e->to === VideoStatus::Ready);
    }

    public function test_clean_removes_only_what_nothing_needs(): void
    {
        $work = Storage::fake('local');
        $kept = Video::create(['prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'status' => VideoStatus::Ready]);
        $work->put($kept->workFile('desktop'), str_repeat('v', 2048));
        $work->put($kept->workPath('desktop/raw.webm'), str_repeat('r', 4096));
        $work->put('video-demo/work/999/video-desktop.mp4', 'old');
        $work->put('video-demo/work/checks/abc/job.json', '{}');

        $this->artisan('video-demo:clean', ['--dry-run' => true])->expectsOutputToContain('Would free')->assertSuccessful();
        $work->assertExists('video-demo/work/999/video-desktop.mp4');

        $this->artisan('video-demo:clean')->expectsOutputToContain('Freed')->assertSuccessful();
        // The finished video stays; its raw recording, a deleted video's files and temporary files go.
        $work->assertExists($kept->workFile('desktop'));
        $work->assertMissing([$kept->workPath('desktop/raw.webm'), 'video-demo/work/999/video-desktop.mp4', 'video-demo/work/checks/abc/job.json']);

        $this->artisan('video-demo:clean')->expectsOutputToContain('Nothing to clean up')->assertSuccessful();
    }

    public function test_pages_have_their_own_favicon(): void
    {
        // The package's own icon, even when the app has one.
        File::put(public_path('favicon.jpg'), 'icon');
        try {
            $this->assertStringStartsWith('data:image/svg+xml,', Favicon::find()['href']);
            $this->get('/video-demo/studio')->assertSee('rel="icon" href="data:image/svg+xml,', false);

            // "app" uses the app's favicon, whatever its format.
            config(['video-demo.ui.favicon' => 'app']);
            $this->assertStringContainsString('/favicon.jpg?v=', Favicon::find()['href']);
            $this->get('/video-demo/studio')->assertSee('favicon.jpg?v=', false);
        } finally {
            File::delete(public_path('favicon.jpg'));
        }

        // With no app favicon to use, the package's own icon stays.
        $this->assertStringStartsWith('data:image/svg+xml,', Favicon::find()['href']);

        config(['video-demo.ui.favicon' => 'https://cdn.example.com/icon.png']);
        $this->assertSame('https://cdn.example.com/icon.png', Favicon::find()['href']);
    }

    public function test_the_camera_zooms_in_on_steps_and_out_for_captions(): void
    {
        $keys = Camera::keyframes([
            ['t' => 1.0, 'x' => 600, 'y' => 300, 'w' => 120, 'h' => 36],
            ['t' => 1.2, 'x' => 100, 'y' => 500, 'w' => 200, 'h' => 40],
            ['t' => 4.0, 'wide' => true],
            ['t' => 5.0, 'x' => 0, 'y' => 0, 'w' => 1280, 'h' => 800],
        ], 1280, 800);

        // Starts at full view, then eases in on the first element, zoomed as far as allowed.
        $this->assertSame(['s' => 0.0, 'z' => 1.0, 'x' => 640.0, 'y' => 400.0], $keys[0]);
        $this->assertSame([0.75, 1.8, 660.0, 318.0], [$keys[1]['s'], $keys[1]['z'], $keys[1]['x'], $keys[1]['y']]);
        // A move that would start before the last one ends waits for it.
        $this->assertSame(1.35, $keys[2]['s']);
        // Captions pull back out; an element the size of the page needs no zoom, so it adds nothing.
        $this->assertSame(1.0, $keys[3]['z']);
        $this->assertCount(4, $keys);

        // A zoom a new page would undo straight away is skipped.
        $this->assertCount(1, Camera::keyframes([
            ['t' => 2.0, 'x' => 600, 'y' => 300, 'w' => 120, 'h' => 36],
            ['t' => 2.5, 'wide' => true],
        ], 1280, 800));
    }
}
