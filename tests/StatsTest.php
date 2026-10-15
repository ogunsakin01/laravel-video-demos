<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Ai\PlanningFailed;
use Ogunsakin01\VideoDemo\Ai\Suggester;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\Stats;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoRequest;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;

/**
 * Counting how the videos are watched, and showing it in the studio.
 */
class StatsTest extends TestCase
{
    use FakesAnthropic;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        app(Manifest::class)->put($this->manifestVideo());
    }

    public function test_nothing_is_counted_unless_the_app_turns_it_on(): void
    {
        $this->assertStringNotContainsString('data-vd-stats="', Blade::render('<x-video-demo::player slug="post-a-job" />'));
        $this->post('/help/videos/post-a-job/events', ['event' => 'play'])->assertNotFound();
        $this->get('/help/videos?q=payroll');

        $this->assertSame(0, DB::table('video_demo_stats')->count());
    }

    public function test_the_player_counts_plays_and_how_far_viewers_get(): void
    {
        config(['video-demo.library.stats' => true]);

        $player = Blade::render('<x-video-demo::player slug="post-a-job" />');
        $this->assertStringContainsString('data-vd-stats="/help/videos/post-a-job/events"', $player);
        $this->assertStringContainsString('navigator.sendBeacon', $player);

        foreach (['play', 'play', 'play', '25', '100'] as $event) {
            $this->post('/help/videos/post-a-job/events', ['event' => $event])->assertNoContent();
        }
        $this->post('/help/videos/post-a-job/events', ['event' => 'rewind'])->assertStatus(422);
        $this->post('/help/videos/nothing-here/events', ['event' => 'play'])->assertNotFound();

        $summary = app(Stats::class)->summary();
        $this->assertSame(['play' => 3, 25 => 1, 50 => 0, 75 => 0, 100 => 1], $summary['videos']['post-a-job']);
        // One row a day for each count.
        $this->assertSame(3, DB::table('video_demo_stats')->count());
        $this->assertSame(3, (int) DB::table('video_demo_stats')->where('event', 'play')->value('hits'));
    }

    public function test_searches_that_find_nothing_are_counted_by_their_words(): void
    {
        config(['video-demo.library.stats' => true]);

        $this->get('/help/videos?q=Run  PAYROLL');
        $this->get('/help/videos?q=run payroll');
        $this->get('/help/videos?q=post a job');

        $this->assertSame([['query' => 'run payroll', 'count' => 2]], app(Stats::class)->summary()['searches']);
    }

    public function test_where_viewers_stop_is_found_from_the_quarters(): void
    {
        $chapters = [['title' => 'Open the form', 'at' => 0], ['title' => 'Fill in the details', 'at' => 20]];

        $this->assertNull(Stats::dropOff(['play' => 3], 60));
        $this->assertSame(
            'Most who stop do so between 25% and 50%, around 0:23, in "Fill in the details".',
            Stats::dropOff(['play' => 100, 25 => 90, 50 => 40, 75 => 35, 100 => 30], 60, $chapters),
        );
        $this->assertNull(Stats::dropOff(['play' => 10, 25 => 10, 50 => 10, 75 => 10, 100 => 10], 60));
    }

    public function test_production_shares_its_counts_with_the_token_and_the_studio_shows_them(): void
    {
        config(['video-demo.library.stats' => true, 'video-demo.library.requests_token' => 'secret']);
        $this->refreshRoutes();
        $stats = app(Stats::class);
        foreach (range(1, 10) as $i) {
            $stats->record('post-a-job', 'play');
        }
        $stats->record('post-a-job', '25');

        $this->getJson('/admin/videos/api/stats')->assertUnauthorized();
        $this->withToken('secret')->getJson('/admin/videos/api/stats?days=7')
            ->assertOk()->assertJsonPath('days', 7)->assertJsonPath('enabled', true)->assertJsonPath('videos.post-a-job.play', 10);

        // The studio, here reading this app's own counts.
        $video = Video::create(['slug' => 'post-a-job', 'title' => 'Post a job', 'prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'published_at' => now(), 'duration_seconds' => 60, 'recordings' => ['desktop' => ['seconds' => 60, 'chapters' => [['title' => 'Start', 'at' => 0]]]]]);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('Watching');
        $this->getJson("/video-demo/studio/{$video->id}/stats")
            ->assertJsonPath('source', 'local')
            ->assertJsonPath('counts.play', 10)
            ->assertJsonPath('drop_off', 'Most who stop do so between 0% and 25%, around 0:08, in "Start".');
    }

    public function test_the_studio_reads_counts_from_production(): void
    {
        config(['video-demo.studio.production_url' => 'https://app.example.com', 'video-demo.library.requests_token' => 'secret']);
        Http::fake(['app.example.com/admin/videos/api/stats*' => Http::response(['days' => 90, 'enabled' => true, 'videos' => [], 'searches' => [['query' => 'export invoices', 'count' => 4]]])]);

        $this->getJson('/video-demo/studio/stats')->assertJsonPath('source', 'https://app.example.com')->assertJsonPath('searches.0.query', 'export invoices');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret') && str_contains($request->url(), 'days=90'));
        $this->get('/video-demo/studio')->assertSee('Searched for, nothing found');
    }

    public function test_ideas_start_with_what_users_looked_for(): void
    {
        config(['video-demo.library.stats' => true]);
        app(Stats::class)->searchMiss('export invoices');
        VideoRequest::create(['question' => 'How do I add a teammate? My key is sk-ant-api03-'.str_repeat('a', 40)]);

        $this->startFakeAnthropic();
        try {
            $this->respond(1, [['type' => 'text', 'text' => json_encode(['suggestions' => []])]], 'end_turn', [], 'claude-sonnet-5-5');
            try {
                app(Suggester::class)->suggest('claude-sonnet-5-5');
            } catch (PlanningFailed) {
                // It suggested nothing; only what it was sent matters here.
            }

            $prompt = $this->request(1)['body']['messages'][0]['content'];
            $this->assertStringContainsString('<what_users_looked_for>', $prompt);
            $this->assertStringContainsString('- searched 1×: export invoices', $prompt);
            $this->assertStringContainsString('- asked: How do I add a teammate?', $prompt);
            // What users type is checked for secrets like everything else sent.
            $this->assertStringNotContainsString(str_repeat('a', 40), $prompt);
        } finally {
            $this->stopFakeAnthropic();
        }
    }

    /**
     * Register the routes again after a config change that adds some.
     */
    protected function refreshRoutes(): void
    {
        (new VideoDemoServiceProvider($this->app))->boot();
        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}
