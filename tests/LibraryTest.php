<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Library\Guide;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Library\Restorer;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoRequest;
use Ogunsakin01\VideoDemo\Support\Routes;

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
        // The written guide is saved with it.
        $this->assertArrayHasKey('guide', $entry);

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

    public function test_a_video_page_has_a_written_guide_and_tells_search_engines_about_it(): void
    {
        $guide = Guide::fromSteps([
            ['action' => 'click', 'target' => ['role' => 'link', 'name' => 'Post a job'], 'chapter' => 'Open the form'],
            ['action' => 'type', 'target' => ['label' => 'Job title'], 'value' => 'Head of Support'],
        ]);
        $manifest = app(Manifest::class);
        $manifest->put($this->manifestVideo(['guide' => $guide, 'title' => 'Post a job </script><script>alert(1)</script>']));
        $manifest->put($this->manifestVideo(['slug' => 'payroll', 'title' => 'Run payroll', 'visibility' => 'private']));

        $page = $this->get('/help/videos/post-a-job')->assertOk();
        $page->assertSee('Step by step')->assertSee('<strong>Post a job</strong>', false)->assertSee('Fill in <strong>Job title</strong>.', false)
            ->assertDontSee('Head of Support');
        // A description, link previews and structured data, with nothing able to end the script.
        $page->assertSee('<meta property="og:video:type" content="video/mp4">', false)->assertSee('<link rel="canonical" href="'.route('video-demo.public.show', 'post-a-job').'">', false);
        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $page->getContent(), $m);
        $schema = json_decode($m[1], true);
        $this->assertSame(['VideoObject', 'PT1M2S'], [$schema['@type'], $schema['duration']]);
        $this->assertStringNotContainsString('</script><script>', $m[1]);

        // Private videos aren't indexed, and have no sitemap.
        $this->get('/admin/videos/payroll')->assertOk()->assertSee('<meta name="robots" content="noindex">', false)->assertDontSee('application/ld+json', false);
        $this->get('/admin/videos/sitemap.xml')->assertNotFound();
        $sitemap = $this->get('/help/videos/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml');
        $this->assertStringContainsString('<loc>'.route('video-demo.public.show', 'post-a-job').'</loc>', $sitemap->getContent());
        $this->assertStringContainsString('<video:duration>62</video:duration>', $sitemap->getContent());
        $this->assertStringNotContainsString('payroll', $sitemap->getContent());
    }

    public function test_videos_published_before_guides_get_one_from_their_saved_plan(): void
    {
        Storage::disk('public')->put('video-demo/post-a-job-v1.json', json_encode(['plan' => ['steps' => [
            ['action' => 'click', 'target' => ['role' => 'button', 'name' => 'Publish job']],
        ]]]));
        app(Manifest::class)->put($this->manifestVideo(['record' => 'video-demo/post-a-job-v1.json']));

        $this->get('/help/videos/post-a-job')->assertOk()->assertSee('Click <strong>Publish job</strong>.', false);
    }

    public function test_frontends_that_arent_blade_get_the_video_as_json_and_a_web_component(): void
    {
        $manifest = app(Manifest::class);
        $manifest->put($this->manifestVideo(['sources' => [
            'desktop' => ['video' => 'video-demo/post-a-job-v1-desktop.mp4', 'poster' => 'video-demo/post-a-job-v1-desktop.jpg', 'subtitles' => null, 'width' => 1280, 'height' => 800, 'duration' => 62.4, 'chapters' => []],
            'mobile' => ['video' => 'video-demo/post-a-job-v1-mobile.mp4', 'poster' => 'video-demo/post-a-job-v1-mobile.jpg', 'subtitles' => 'video-demo/post-a-job-v1-mobile.vtt', 'width' => 390, 'height' => 844, 'duration' => 66.0, 'chapters' => []],
        ]]));
        $manifest->put($this->manifestVideo(['slug' => 'payroll', 'visibility' => 'private']));

        // Any frontend may fetch a public video, with full addresses for each version.
        $json = $this->getJson('/help/videos/post-a-job.json')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertSame(['desktop', 'mobile'], array_keys($json->json('sources')));
        $this->assertStringStartsWith('http', $json->json('sources.mobile.video'));
        $this->assertStringEndsWith('post-a-job-v1-mobile.vtt', $json->json('sources.mobile.subtitles'));
        $this->assertSame(route('video-demo.public.show', 'post-a-job'), $json->json('page'));
        // A private one isn't public.
        $this->getJson('/help/videos/payroll.json')->assertNotFound();
        $this->getJson('/help/videos/missing.json')->assertNotFound();

        // Plays are counted from other sites too, when the app counts them: without a CSRF token.
        $this->assertNull($json->json('stats'));
        config(['video-demo.library.stats' => true]);
        $this->assertSame(route('video-demo.public.events', 'post-a-job'), $this->getJson('/help/videos/post-a-job.json')->json('stats'));
        // Whatever the web group checks CSRF with (Laravel 13 calls it PreventRequestForgery), the events route skips it.
        $csrf = collect(app(Kernel::class)->getMiddlewareGroups()['web'])->first(fn ($m) => preg_match('/Csrf|Forgery/', $m));
        $this->assertNotNull($csrf);
        $this->assertContains($csrf, Route::getRoutes()->getByName('video-demo.public.events')->excludedMiddleware());

        $script = $this->get('/help/videos/embed.js')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringStartsWith('application/javascript', $script->headers->get('Content-Type'));
        $this->assertStringContainsString("customElements.define('video-demo-player'", $script->getContent());
    }

    public function test_a_frontends_pages_list_their_videos_by_path(): void
    {
        // Published from a separate frontend, its pages are kept by their path.
        config(['video-demo.frontend.url' => 'http://localhost:5173']);
        $this->assertSame([['name' => null, 'uri' => 'jobs/new']], Routes::describe(['/jobs/new', '/jobs/new?tab=2']));

        $manifest = app(Manifest::class);
        $manifest->put($this->manifestVideo(['routes' => [['name' => null, 'uri' => 'jobs/new']]]));
        $manifest->put($this->manifestVideo(['slug' => 'payroll', 'title' => 'Run payroll', 'visibility' => 'private', 'routes' => [['name' => null, 'uri' => 'jobs/new']]]));

        $json = $this->getJson('/help/videos/for-page.json?path=/jobs/new')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertSame(['post-a-job'], array_column($json->json('videos'), 'slug'));
        $this->assertSame([], $this->getJson('/help/videos/for-page.json?path=/settings')->json('videos'));
        $this->assertStringContainsString("customElements.define('video-demo-help'", $this->get('/help/videos/embed.js')->getContent());
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

    public function test_players_can_pause_while_the_viewer_is_away(): void
    {
        app(Manifest::class)->put($this->manifestVideo());
        $marked = '/<video[^>]*\sdata-vd-away[\s>]/';
        $render = fn (string $blade) => $this->blade($blade)->__toString();

        // Off unless the app or the page turns it on, but the script is always there.
        $html = $render('<x-video-demo::player slug="post-a-job" />');
        $this->assertDoesNotMatchRegularExpression($marked, $html);
        $this->assertStringContainsString('class AttentionTracker', $html);
        $this->assertMatchesRegularExpression($marked, $render('<x-video-demo::player slug="post-a-job" pause-when-away />'));
        $this->assertMatchesRegularExpression($marked, $render('<x-video-demo::player slug="post-a-job" collapsible :pause-when-away="true" />'));

        // Turned on for every player, a page can still turn it off.
        config(['video-demo.library.player.pause_when_away' => true]);
        $this->assertMatchesRegularExpression($marked, $render('<x-video-demo::player slug="post-a-job" />'));
        $this->assertDoesNotMatchRegularExpression($marked, $render('<x-video-demo::player slug="post-a-job" :pause-when-away="false" />'));

        // <video-demo-player> gets the app's setting with the video.
        $this->assertTrue($this->getJson('/help/videos/post-a-job.json')->json('player.pause_when_away'));
        config(['video-demo.library.player.pause_when_away' => false]);
        $this->assertFalse($this->getJson('/help/videos/post-a-job.json')->json('player.pause_when_away'));
    }
}
