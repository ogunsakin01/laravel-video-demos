<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Library\Restorer;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoRequest;

/**
 * Tests publishing, the video library and the player component.
 */
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
        // Desktop is the main version; each version keeps its own size and length.
        $this->assertSame('video-demo/post-a-job-v1-desktop.mp4', $entry['video']);
        $this->assertSame(390, $entry['sources']['mobile']['width']);
        $this->assertSame(66.0, (float) $entry['sources']['mobile']['duration']);

        // A new cut replaces the old files.
        $video->update(['version' => 2]);
        app(Publisher::class)->publish($video);
        Storage::disk('public')->assertMissing(['video-demo/post-a-job-v1-desktop.mp4', 'video-demo/post-a-job-v1-mobile.jpg']);
        Storage::disk('public')->assertExists('video-demo/post-a-job-v2-mobile.mp4');

        app(Publisher::class)->unpublish($video);
        $this->assertNull(app(Manifest::class)->find('post-a-job', 'public'));
        Storage::disk('public')->assertMissing(['video-demo/post-a-job-v2-desktop.mp4', 'video-demo/post-a-job-v2-mobile.mp4']);
    }

    public function test_published_videos_the_studio_lost_are_brought_back(): void
    {
        $video = Video::create([
            'title' => 'Post a job', 'prompt' => 'How a recruiter posts a job', 'target_seconds' => 45,
            'model' => 'claude-opus-5-5', 'effort' => 'high', 'visibility' => 'private', 'voice' => 'kokoro:af_heart',
            'status' => VideoStatus::Ready, 'version' => 3, 'duration_seconds' => 40.0, 'devices' => ['desktop'],
            'plan' => ['title' => 'Post a job', 'summary' => 'Posting a job.', 'steps' => [['action' => 'goto', 'url' => '/jobs']]],
            'recordings' => ['desktop' => ['seconds' => 40.0, 'chapters' => [['title' => 'Start', 'at' => 0.0]]]],
        ]);
        Storage::disk('local')->put($video->workFile('desktop'), 'mp4');
        Storage::disk('local')->put($video->workFile('desktop', 'poster'), 'jpg');
        app(Publisher::class)->publish($video);
        Storage::disk('public')->assertExists('video-demo/post-a-job-v3.json');

        // One published before records were saved: only what the library lists.
        app(Manifest::class)->put($this->manifestVideo(['slug' => 'run-payroll', 'title' => 'Run payroll', 'summary' => 'Paying the team.']));
        Storage::disk('public')->put('video-demo/post-a-job-v1.mp4', 'old-mp4');

        // The studio's database is reset; the library is not.
        Video::query()->delete();
        $this->get(route('video-demo.studio.index'))->assertOk()
            ->assertSee("2 published videos aren't in the studio")->assertSee('Post a job, Run payroll')->assertSee('Bring them back');

        $this->post(route('video-demo.studio.restore'))->assertRedirect(route('video-demo.studio.index'))
            ->assertSessionHas('status', 'Brought back 2 published videos. 1 was published before plans were saved with videos, so to record it again, ask for a new plan.');

        $back = Video::where('slug', 'post-a-job')->first();
        $this->assertSame([VideoStatus::Ready, 3, 'private', 'How a recruiter posts a job', 'kokoro:af_heart', '/jobs'], [$back->status, $back->version, $back->visibility, $back->prompt, $back->voice, $back->plan['steps'][0]['url']]);
        $this->assertNotNull($back->published_at);
        // Its files are back on the work disk, so it plays and can be published again.
        $this->assertSame('mp4', Storage::disk('local')->get($back->workFile('desktop')));
        $this->assertTrue($back->hasRecording());
        app(Publisher::class)->publish($back->fresh());

        $old = Video::where('slug', 'run-payroll')->first();
        $this->assertSame(['Run payroll', 'Paying the team.', false], [$old->title, $old->prompt, $old->hasPlan()]);
        $this->assertSame('old-mp4', Storage::disk('local')->get($old->workFile('desktop')));

        // Nothing left to bring back.
        $this->get(route('video-demo.studio.index'))->assertDontSee('Bring them back');
        $this->assertSame([], app(Restorer::class)->restore());
    }

    public function test_install_brings_back_published_videos_the_studio_lost(): void
    {
        app(Manifest::class)->put($this->manifestVideo(['slug' => 'run-payroll', 'title' => 'Run payroll']));

        try {
            $this->artisan('video-demo:install', ['--no-browser' => true, '--no-sample-data' => true])
                ->expectsOutputToContain("Brought back 1 published video the studio's database no longer had: Run payroll.")
                ->assertSuccessful();
            $this->assertTrue(Video::where('slug', 'run-payroll')->exists());
        } finally {
            File::delete([config_path('video-demo.php'), storage_path('video-demo/.gitignore')]);
        }
    }

    public function test_videos_can_be_kept_in_the_public_folder_and_deployed_with_the_code(): void
    {
        // The package's disk is the app's public folder.
        $this->assertSame(['local', public_path()], [config('filesystems.disks.video-demo-repo.driver'), config('filesystems.disks.video-demo-repo.root')]);

        // Here a temporary folder stands in for it.
        $public = sys_get_temp_dir().'/video-demo-repo-'.uniqid();
        config(['video-demo.storage.disk' => 'video-demo-repo', 'filesystems.disks.video-demo-repo.root' => $public]);
        Storage::forgetDisk('video-demo-repo');

        try {
            $manifest = app(Manifest::class);
            $manifest->put($this->manifestVideo(['video' => 'video-demo/post-a-job-v1.mp4']));
            $this->assertFileExists("$public/video-demo/manifest.json");
            // The studio says what puts it live.
            $this->assertSame(' Commit public/video-demo and deploy to put it live.', Publisher::deployNote());
            // Played from the app's own address, wherever it runs.
            $this->assertSame('/video-demo/post-a-job-v1.mp4', $manifest->url($manifest->find('post-a-job', 'public')));

            // A deploy brings a new manifest: production shows it at once, not when its cache runs out.
            $this->assertNull($manifest->find('payroll', 'public'));
            File::put("$public/video-demo/manifest.json", json_encode(['videos' => [$this->manifestVideo(['slug' => 'payroll'])]]));
            touch("$public/video-demo/manifest.json", time() + 5);
            $this->assertNotNull($manifest->find('payroll', 'public'));
        } finally {
            File::deleteDirectory($public);
        }
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

        // With versions, the player carries them all and can be told which to play.
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
