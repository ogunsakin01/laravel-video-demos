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
                $section = fn ($id, $kind, $heading, $selector) => ['id' => $id, 'selector' => $selector, 'kind' => $kind, 'tag' => in_array($kind, ['panel', 'empty state'], true) ? 'div' : $kind, 'classes' => [],
                    'heading' => $heading, 'text' => $heading.' and more', 'box' => ['x' => 0, 'y' => 100 * $id, 'w' => 800, 'h' => 300]];

                return ['pages' => [
                    ['path' => '/jobs', 'title' => 'Open jobs', 'sections' => [$section(1, 'header', '', 'body > header'), $section(2, 'main', 'Open jobs', 'body > main'), $section(3, 'section', 'Listings', 'main > section')]],
                    ['path' => '/jobs/create', 'title' => 'Post a job', 'sections' => [$section(1, 'form', 'Job details', 'main > form'), $section(2, 'aside', 'Tips', 'main > aside')]],
                ], 'failed' => []];
            }

            public function shootSpots(array $spots, string $html, string $outDir, array $screens = ['desktop', 'mobile'], ?string $recordAs = null, string $accent = '#e5693d'): array
            {
                $this->shot = compact('spots', 'html', 'screens');
                File::ensureDirectoryExists($outDir);
                $results = [];
                foreach ($spots as $i => $spot) {
                    $shots = [];
                    foreach ($screens as $screen) {
                        foreach (['closed', 'open'] as $state) {
                            File::put($shots[$screen][$state] = "$outDir/".($i + 1)."-$screen-$state.jpg", 'jpg');
                        }
                    }
                    // The jobs page's main squeezes its sidebar on phones, whatever the position.
                    $problems = $spot['path'] === '/jobs' ? [['screen' => 'mobile', 'state' => 'open', 'problem' => 'It changes the width of “Tips”.']] : [];
                    $results[] = ['found' => true, 'position' => $spot['positions'][0], 'ok' => $problems === [], 'problems' => $problems, 'shots' => $shots];
                }

                return $results;
            }
        };
        $this->app->instance(Recorder::class, $recorder);

        return $recorder;
    }

    public function test_the_ai_picks_spots_and_each_is_shown_with_where_to_paste_it(): void
    {
        $recorder = $this->fakeRecorder();
        File::ensureDirectoryExists(base_path('resources/views/jobs'));
        File::put(base_path('resources/views/jobs/create.blade.php'), "<x-layout>\n  <p>Intro</p>\n  <form method=\"post\">\n    <h2>Job details</h2>\n    <input name=\"title\">\n  </form>\n</x-layout>\n");
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
            // Positions to try, picked one first; inside only, for a whole page's main.
            $this->assertSame([['before', 'top', 'after'], ['top']], array_column($recorder->shot['spots'], 'positions'));
            $this->assertSame(['desktop' => ['closed' => '1-desktop-closed.jpg', 'open' => '1-desktop-open.jpg'], 'mobile' => ['closed' => '1-mobile-closed.jpg', 'open' => '1-mobile-open.jpg']], $spots['items'][0]['shots']);
            // The layout check is kept with each spot.
            $this->assertSame([true, false], array_column($spots['items'], 'fits'));
            $this->assertSame('It changes the width of “Tips”.', $spots['items'][1]['problems'][0]['problem']);
            // The real collapsible player, with the video's title and length, is what goes on the page.
            $this->assertStringContainsString('data-vd-collapsible="'.$video->slug.'"', $recorder->shot['html']);
            $this->assertStringContainsString('How-to video · 1:02', $recorder->shot['html']);
            $this->assertStringContainsString('data:image/jpeg;base64,', $recorder->shot['html']);
            // Pasted just above the form's opening tag, with the code around it.
            $tag = '<x-video-demo::player slug="'.$video->slug.'" collapsible />';
            $code = $spots['items'][0]['code'];
            $this->assertSame(['resources/views/jobs/create.blade.php', 3, true], [$code['file'], $code['line'], $code['exact']]);
            $this->assertSame([[1, '<x-layout>', false], [2, '  <p>Intro</p>', false], [3, '  '.$tag, true], [4, '  <form method="post">', false], [5, '    <h2>Job details</h2>', false], [6, '    <input name="title">', false]],
                array_map(fn ($l) => [$l['n'], $l['text'], $l['added']], $code['excerpt']));

            // The model saw the sections, and its cost went on the video and the ledger.
            $sent = $this->request(1)['body']['messages'][0]['content'];
            $this->assertStringContainsString('<page path="/jobs/create" title="Post a job">', $sent);
            $this->assertStringContainsString('[1] form "Job details"', $sent);
            // Its cost is the video's, in the total spent, and shown with the spots.
            $this->assertGreaterThan(0, $video->fresh()->cost);
            $this->assertEqualsWithDelta($video->fresh()->cost, $spots['cost'], 0.000001);
            $this->assertSame(1, Spend::where('kind', 'spots')->where('video_id', $video->id)->count());
            $this->assertEqualsWithDelta($spots['cost'], Spend::total(), 0.000001);
            $this->get('/video-demo/studio')->assertSee('$'.number_format(Spend::total(), 4), false);

            // The studio shows each spot, its screenshots and the tag to paste.
            $this->get("/video-demo/studio/{$video->id}")
                ->assertSee('Where to put it')->assertSee('Above the job form')->assertSee('Recruiters land here when they start a listing.')
                ->assertSee('Open <span class="vd-mono">resources/views/jobs/create.blade.php</span> at line 3', false)
                ->assertSee('data-copy="'.e($tag).'"', false)
                ->assertSee('<span class="is-added"><b>+</b>  '.e($tag).'</span>', false)
                ->assertSee('Fits on desktop and phone')->assertSee('May upset the layout')->assertSee('What the check found')
                ->assertSee('(phone, open)')
                ->assertSee('Minimised')->assertSee('Suggest again')
                ->assertSee('AI cost <span class="vd-num">$'.number_format($spots['cost'], 4).'</span>', false);
            $this->get("/video-demo/studio/{$video->id}/spots/1-mobile-open.jpg")->assertOk();
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

        config(['video-demo.ai.model' => 'claude-sonnet-5-5']);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('Suggest where to put it')->assertSee('costs about $0.0', false)->assertSee("added to the video's cost", false);
        $this->post("/video-demo/studio/{$video->id}/spots")->assertRedirect(route('video-demo.studio.show', $video).'#vd-spots');
        $this->post("/video-demo/studio/{$video->id}/spots")->assertRedirect();
        Queue::assertPushed(FindSpots::class, 1);

        $this->assertSame('working', $video->fresh()->spots['status']);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('Waiting for a queue worker')->assertSee('data-spots-working', false)->assertDontSee('Suggest where to put it');
        $this->getJson("/video-demo/studio/{$video->id}/status")->assertJsonPath('spots', 'working')
            ->assertJsonPath('spots_progress.stage', 'Waiting for a queue worker')->assertJsonPath('spots_progress.percent', 0);

        // Each stage moves the bar on; the time left counts what is still to come.
        $video->update(['spots' => [...$video->fresh()->spots, 'stage' => 'picking', 'stage_at' => now()->subSeconds(5)->toIso8601String()]]);
        $progress = FindSpots::progress($video->fresh());
        $this->assertSame('The AI is picking spots', $progress['stage']);
        $this->assertGreaterThan(25, $progress['percent']);
        $this->assertLessThan(80, $progress['percent']);
        $this->assertGreaterThan(30, $progress['remaining']);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('id="vd-spots-progress"', false)->assertSee('The AI is picking spots');

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
