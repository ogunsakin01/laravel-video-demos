<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoRequest;

class LibraryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_publishing_copies_files_and_lists_the_video(): void
    {
        $video = Video::create([
            'title' => 'Post a job', 'prompt' => 'How to post a job', 'target_seconds' => 60,
            'model' => 'claude-opus-5-5', 'effort' => 'high', 'visibility' => 'public',
            'status' => VideoStatus::Ready, 'version' => 1, 'duration_seconds' => 61.5,
            'plan' => ['title' => 'Post a job', 'summary' => 'Posting a job.', 'steps' => [], 'chapters' => []],
            'devices' => ['desktop', 'mobile'],
            'recordings' => [
                'desktop' => ['seconds' => 61.5, 'chapters' => [['title' => 'Start', 'at' => 1.0]]],
                'mobile' => ['seconds' => 66.0, 'chapters' => [['title' => 'Start', 'at' => 1.4]]],
            ],
        ]);
        foreach (['desktop', 'mobile'] as $device) {
            Storage::disk('local')->put($video->workFile($device), "mp4-$device");
            Storage::disk('local')->put($video->workFile($device, 'poster'), "jpg-$device");
        }

        app(Publisher::class)->publish($video);

        Storage::disk('public')->assertExists([
            'video-demo/post-a-job-v1-desktop.mp4', 'video-demo/post-a-job-v1-desktop.jpg',
            'video-demo/post-a-job-v1-mobile.mp4', 'video-demo/post-a-job-v1-mobile.jpg', 'video-demo/manifest.json',
        ]);
        $this->assertNotNull($video->fresh()->published_at);
        $entry = app(Manifest::class)->find('post-a-job', 'public');
        $this->assertSame('Posting a job.', $entry['summary']);
        // Desktop is the main version; each version keeps its own size and length
        $this->assertSame('video-demo/post-a-job-v1-desktop.mp4', $entry['video']);
        $this->assertSame(390, $entry['sources']['mobile']['width']);
        $this->assertSame(66.0, (float) $entry['sources']['mobile']['duration']);

        // A new cut replaces the old files
        $video->update(['version' => 2]);
        app(Publisher::class)->publish($video);
        Storage::disk('public')->assertMissing(['video-demo/post-a-job-v1-desktop.mp4', 'video-demo/post-a-job-v1-mobile.jpg']);
        Storage::disk('public')->assertExists('video-demo/post-a-job-v2-mobile.mp4');

        app(Publisher::class)->unpublish($video);
        $this->assertNull(app(Manifest::class)->find('post-a-job', 'public'));
        Storage::disk('public')->assertMissing(['video-demo/post-a-job-v2-desktop.mp4', 'video-demo/post-a-job-v2-mobile.mp4']);
    }

    public function test_public_library_hides_private_videos(): void
    {
        $manifest = app(Manifest::class);
        $manifest->put($this->manifestVideo());
        $manifest->put($this->manifestVideo(['slug' => 'payroll', 'title' => 'Run payroll', 'visibility' => 'private']));

        $this->get('/help/videos')->assertOk()->assertSee('Post a job')->assertDontSee('Run payroll');
        $this->get('/help/videos/payroll')->assertNotFound();
        $this->get('/admin/videos')->assertOk()->assertSee('Run payroll');
        $this->get('/help/videos/post-a-job')->assertOk()->assertSee('Fill in the details')->assertSee('post-a-job-v1.mp4');
    }

    public function test_search_finds_by_title_and_chapters_and_takes_requests(): void
    {
        app(Manifest::class)->put($this->manifestVideo());

        $this->get('/help/videos?q=details')->assertOk()->assertSee('Post a job');
        $this->get('/help/videos?q=invoices')->assertOk()->assertSee('No video covers that yet')->assertSee('Ask for a video on this');

        $this->post('/help/videos/requests', ['question' => 'How do I send invoices?'])->assertRedirect();
        $this->assertSame('How do I send invoices?', VideoRequest::first()->question);
    }

    public function test_player_component_renders_published_videos_only(): void
    {
        app(Manifest::class)->put($this->manifestVideo());

        $this->assertStringContainsString('post-a-job-v1.mp4', $this->blade('<x-video-demo::player slug="post-a-job" />')->__toString());

        // With versions, the player carries them all and can be told which to play
        app(Manifest::class)->put($this->manifestVideo(['sources' => [
            'desktop' => ['video' => 'video-demo/d.mp4', 'poster' => 'video-demo/d.jpg', 'width' => 1280, 'height' => 800, 'duration' => 10, 'chapters' => []],
            'mobile' => ['video' => 'video-demo/m.mp4', 'poster' => 'video-demo/m.jpg', 'width' => 390, 'height' => 844, 'duration' => 12, 'chapters' => []],
        ]]));
        $html = $this->blade('<x-video-demo::player slug="post-a-job" device="mobile" />')->__toString();
        $this->assertStringContainsString('src="/storage/video-demo/m.mp4"', $html);
        $this->assertStringContainsString('aspect-ratio:390/844', $html);
        $this->assertStringContainsString('video-demo\\/d.mp4', $html);
        $this->assertSame('', trim($this->blade('<x-video-demo::player slug="missing" />')->__toString()));
    }
}
