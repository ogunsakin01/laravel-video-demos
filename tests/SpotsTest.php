<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Ai\Placer;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\FindSpots;
use Ogunsakin01\VideoDemo\Models\Spend;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Studio\ViewFinder;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;

/**
 * "Where to put it": suggesting where in the app a finished video should go,
 * with a screenshot of each spot.
 */
class SpotsTest extends TestCase
{
    use FakesAnthropic;

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/video-demo-spots-'.uniqid();
        config(['filesystems.disks.local.root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    protected function readyVideo(array $overrides = []): Video
    {
        $video = Video::create([
            'title' => 'Post a job', 'prompt' => 'How a recruiter posts a job', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop'], 'duration_seconds' => 62, 'recordings' => ['desktop' => ['seconds' => 62, 'chapters' => []]],
            'start_path' => '/jobs', 'plan' => ['title' => 'Post a job', 'summary' => 'From the jobs page to a live listing.', 'steps' => [
                ['action' => 'goto', 'url' => '/jobs'], ['action' => 'goto', 'url' => '/jobs/create'], ['action' => 'goto', 'url' => '/jobs'],
            ]],
            ...$overrides,
        ]);
        Storage::disk('local')->put($video->workFile('desktop'), 'mp4');
        Storage::disk('local')->put($video->workFile('desktop', 'poster'), 'jpg');

        return $video;
    }

    /** A recorder that maps two pages and "photographs" each spot. */
    protected function fakeRecorder(): Recorder
    {
        $recorder = new class extends Recorder
        {
            public array $mapped = [];

            public array $shot = [];

            public function mapPages(array $paths, ?string $recordAs = null): array
            {
                $this->mapped = $paths;
                $section = fn ($id, $kind, $heading, $selector) => ['id' => $id, 'selector' => $selector, 'kind' => $kind, 'heading' => $heading, 'text' => $heading.' and more', 'box' => ['x' => 0, 'y' => 100 * $id, 'w' => 800, 'h' => 300]];

                return ['pages' => [
                    ['path' => '/jobs', 'title' => 'Open jobs', 'sections' => [$section(1, 'header', '', 'body > header'), $section(2, 'main', 'Open jobs', 'body > main'), $section(3, 'section', 'Listings', 'main > section')]],
                    ['path' => '/jobs/create', 'title' => 'Post a job', 'sections' => [$section(1, 'form', 'Job details', 'main > form'), $section(2, 'aside', 'Tips', 'main > aside')]],
                ], 'failed' => []];
            }

            public function shootSpots(array $spots, array $card, string $outDir, array $screens = ['desktop', 'mobile'], ?string $recordAs = null): array
            {
                $this->shot = compact('spots', 'card', 'screens');
                File::ensureDirectoryExists($outDir);
                $shots = [];
                foreach ($spots as $i => $spot) {
                    foreach ($screens as $screen) {
                        File::put($file = "$outDir/".($i + 1)."-$screen.jpg", 'jpg');
                        $shots[] = ['spot' => $i, 'screen' => $screen, 'file' => $file];
                    }
                }

                return ['shots' => $shots, 'missing' => []];
            }
        };
        $this->app->instance(Recorder::class, $recorder);

        return $recorder;
    }

    public function test_the_ai_picks_spots_and_each_is_shown_with_where_to_paste_it(): void
    {
        $recorder = $this->fakeRecorder();
        File::ensureDirectoryExists(base_path('resources/views/jobs'));
        File::put(base_path('resources/views/jobs/create.blade.php'), "<x-layout>\n  <form>\n    <h2>Job details</h2>\n  </form>\n</x-layout>\n");
        $this->startFakeAnthropic();
        try {
            $this->respond(1, [['type' => 'text', 'text' => json_encode(['spots' => [
                ['path' => '/jobs/create', 'section' => 1, 'position' => 'before', 'label' => 'Above the job form', 'why' => 'Recruiters land here when they start a listing.'],
                ['path' => '/jobs', 'section' => 2, 'position' => 'before', 'label' => 'Top of the jobs page', 'why' => 'Where recruiters begin.'],
                ['path' => '/jobs', 'section' => 9, 'position' => 'top', 'label' => 'Not there', 'why' => 'x'],
            ]])]], 'end_turn', ['input_tokens' => 3_000, 'output_tokens' => 200, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0], 'claude-sonnet-5-5');
            config(['video-demo.ai.model' => 'claude-sonnet-5-5']);
            $video = $this->readyVideo();

            FindSpots::dispatchSync($video);
            $spots = $video->fresh()->spots;

            $this->assertSame('ready', $spots['status'], $spots['error'] ?? '');
            // The video's own pages, each once, starting where it starts.
            $this->assertSame(['/jobs', '/jobs/create'], $recorder->mapped);
            // A section that isn't on the page is dropped; beside a whole page's main, the card goes inside it.
            $this->assertSame([['/jobs/create', 'before', 'main > form'], ['/jobs', 'top', 'body > main']], array_map(fn ($s) => [$s['path'], $s['position'], $s['selector']], $spots['items']));
            $this->assertSame(['desktop' => '1-desktop.jpg', 'mobile' => '1-mobile.jpg'], $spots['items'][0]['shots']);
            $this->assertSame(['file' => 'resources/views/jobs/create.blade.php', 'line' => 3, 'text' => '<h2>Job details</h2>'], $spots['items'][0]['code']);
            $this->assertSame(['title' => 'Post a job', 'length' => '1:02'], array_intersect_key($recorder->shot['card'], array_flip(['title', 'length'])));

            // The model saw the sections, and its cost went on the video and the ledger.
            $sent = $this->request(1)['body']['messages'][0]['content'];
            $this->assertStringContainsString('<page path="/jobs/create" title="Post a job">', $sent);
            $this->assertStringContainsString('[1] form "Job details"', $sent);
            $this->assertGreaterThan(0, $video->fresh()->cost);
            $this->assertSame(1, Spend::where('kind', 'spots')->where('video_id', $video->id)->count());

            // The studio shows each spot, its screenshots and the tag to paste.
            $this->get("/video-demo/studio/{$video->id}")
                ->assertSee('Where to put it')->assertSee('Above the job form')->assertSee('Recruiters land here when they start a listing.')
                ->assertSee('resources/views/jobs/create.blade.php:3')
                ->assertSee('&lt;x-video-demo::player slug=&quot;'.$video->slug.'&quot; /&gt;', false)
                ->assertSee('Suggest again');
            $this->get("/video-demo/studio/{$video->id}/spots/1-mobile.jpg")->assertOk();
            $this->get("/video-demo/studio/{$video->id}/spots/9-mobile.jpg")->assertNotFound();
            $this->get("/video-demo/studio/{$video->id}/spots/..%2F..%2Fsecret.jpg")->assertNotFound();
        } finally {
            $this->stopFakeAnthropic();
            File::deleteDirectory(base_path('resources/views/jobs'));
        }
    }

    public function test_without_an_ai_key_it_suggests_the_top_of_the_start_page(): void
    {
        $this->fakeRecorder();
        config(['video-demo.ai.api_key' => null, 'prism.providers' => []]);
        $video = $this->readyVideo();

        FindSpots::dispatchSync($video);
        $spots = $video->fresh()->spots;

        $this->assertSame('ready', $spots['status'], $spots['error'] ?? '');
        $this->assertSame([['/jobs', 'top', 'body > main']], array_map(fn ($s) => [$s['path'], $s['position'], $s['selector']], $spots['items']));
        $this->assertSame('With no AI key set, this is the top of the page the video starts on.', $spots['note']);
        $this->assertSame(0.0, $video->fresh()->cost);
    }

    public function test_a_video_past_its_spending_limit_gets_spots_without_ai(): void
    {
        $this->fakeRecorder();
        $video = $this->readyVideo(['cost' => 0.6, 'max_cost' => 0.5]);

        FindSpots::dispatchSync($video);

        $this->assertStringContainsString('reached its $0.50 spending limit', $video->fresh()->spots['note']);
    }

    public function test_the_button_queues_it_once_and_the_panel_waits_for_it(): void
    {
        Queue::fake();
        $video = $this->readyVideo();

        $this->get("/video-demo/studio/{$video->id}")->assertSee('Suggest where to put it')->assertSee('for about a cent');
        $this->post("/video-demo/studio/{$video->id}/spots")->assertRedirect(route('video-demo.studio.show', $video).'#vd-spots');
        $this->post("/video-demo/studio/{$video->id}/spots")->assertRedirect();
        Queue::assertPushed(FindSpots::class, 1);

        $this->assertSame('working', $video->fresh()->spots['status']);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('Opening your pages and picking spots')->assertSee('data-spots-working', false)->assertDontSee('Suggest where to put it');
        $this->getJson("/video-demo/studio/{$video->id}/status")->assertJsonPath('spots', 'working');

        // A failure says why, and can be tried again.
        $video->update(['spots' => ['status' => 'failed', 'at' => now()->toIso8601String(), 'error' => 'None of the video\'s pages could be opened: /jobs gave HTTP 500.', 'items' => []]]);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('/jobs gave HTTP 500')->assertSee('Try again');

        // Only a finished video can be placed.
        $video->update(['status' => VideoStatus::Failed]);
        $this->post("/video-demo/studio/{$video->id}/spots")->assertStatus(409);
    }

    public function test_views_are_found_by_the_text_they_show(): void
    {
        File::ensureDirectoryExists(base_path('resources/views/jobs'));
        File::put(base_path('resources/views/jobs/index.blade.php'), "<h1>Open jobs</h1>\n<h2>Listings</h2>\n");
        File::put(base_path('resources/views/dashboard.blade.php'), "<h2>Listings</h2>\n");
        try {
            // The view whose name matches the page wins.
            $this->assertSame(['file' => 'resources/views/jobs/index.blade.php', 'line' => 2, 'text' => '<h2>Listings</h2>'], ViewFinder::find('Listings', '/jobs'));
            $this->assertNull(ViewFinder::find('Shown from the database', '/jobs'));
            $this->assertNull(ViewFinder::find('', '/jobs'));
        } finally {
            File::deleteDirectory(base_path('resources/views/jobs'));
            File::delete(base_path('resources/views/dashboard.blade.php'));
        }
    }

    public function test_the_fallback_skips_headers_and_navigation(): void
    {
        $pages = [['path' => '/x', 'title' => 'X', 'sections' => [
            ['id' => 1, 'kind' => 'nav', 'heading' => '', 'selector' => 'nav'],
            ['id' => 2, 'kind' => 'panel', 'heading' => 'Inbox', 'selector' => '.inbox'],
        ]]];

        $this->assertSame(['/x', '.inbox', 'top'], [Placer::fallback($pages)[0]['path'], Placer::fallback($pages)[0]['section']['selector'], Placer::fallback($pages)[0]['position']]);
        $this->assertSame([], Placer::fallback([['path' => '/y', 'title' => 'Y', 'sections' => []]]));
    }
}
