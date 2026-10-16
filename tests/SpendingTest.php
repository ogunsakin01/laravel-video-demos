<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Ai\Usage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Spend;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Studio\Progress;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;

/**
 * Tests the spending ledger behind the total on the videos page, the page's
 * live updates, and the progress bar on a video being made.
 */
class SpendingTest extends TestCase
{
    use FakesAnthropic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startFakeAnthropic();
    }

    protected function tearDown(): void
    {
        $this->stopFakeAnthropic();
        parent::tearDown();
    }

    protected function video(array $overrides = []): Video
    {
        return Video::create([
            'prompt' => 'How to post a job', 'target_seconds' => 60, 'model' => 'claude-sonnet-5-5',
            'effort' => 'medium', 'status' => VideoStatus::Ready, 'sample_data' => false, ...$overrides,
        ]);
    }

    public function test_costs_added_at_once_by_two_processes_all_count(): void
    {
        // A job and the studio each hold their own copy of the video.
        $job = $this->video();
        $studio = Video::findOrFail($job->id);

        $job->addUsage(new Usage(input: 100, cost: 0.10));
        $studio->addUsage(new Usage(input: 50, cost: 0.05));
        $job->addUsage(new Usage(output: 10, cost: 0.01));

        $saved = $job->fresh();
        $this->assertEqualsWithDelta(0.16, $saved->cost, 0.000001);
        $this->assertSame([150, 10], [$saved->input_tokens, $saved->output_tokens]);
        // Each copy reads back the total, so the next limit check sees everything.
        $this->assertEqualsWithDelta(0.16, $job->cost, 0.000001);
        // A later save of a copy doesn't write an old total back.
        $studio->update(['title' => 'Renamed']);
        $this->assertEqualsWithDelta(0.16, $job->fresh()->cost, 0.000001);
    }

    public function test_every_ai_cost_is_added_to_the_total_and_kept_after_a_delete(): void
    {
        $video = $this->video();
        $video->addUsage(new Usage(cost: 0.25));
        $video->addUsage(new Usage(cost: 0.02), 'voice');

        $this->respond(1, [['type' => 'text', 'text' => json_encode(['title' => 'Post a job', 'prompt' => 'How to post a job.', 'start_path' => '', 'target_seconds' => 60])]],
            'end_turn', ['input_tokens' => 2_000, 'output_tokens' => 300, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0], 'claude-sonnet-5-5');
        $this->postJson('/video-demo/studio/complete', ['prompt' => 'post job', 'model' => 'claude-sonnet-5-5'])->assertOk();

        $this->assertSame(['plan', 'voice', 'complete'], Spend::orderBy('id')->pluck('kind')->all());
        $this->assertEqualsWithDelta(0.277, Spend::total(), 0.0001);
        // The video's own cost is what was spent on it; Complete with AI isn't part of it.
        $this->assertEqualsWithDelta(0.27, $video->fresh()->cost, 0.0001);

        $this->get('/video-demo/studio')->assertSee('$0.2770')->assertSee('including Complete with AI and ideas');

        $video->delete();
        $this->assertEqualsWithDelta(0.277, Spend::total(), 0.0001);
    }

    public function test_the_videos_page_gets_the_total_and_each_videos_status(): void
    {
        $working = $this->video(['status' => VideoStatus::Recording, 'cost' => 0.1]);
        $done = $this->video(['duration_seconds' => 64.4]);
        Spend::record('plan', 0.1, $working->id);

        $this->get('/video-demo/studio')
            ->assertSee('data-video="'.$working->id.'"', false)
            ->assertSee('data-working="1"', false)
            ->assertSee('studio\\/spending', false);

        $this->getJson("/video-demo/studio/spending?videos={$working->id},{$done->id},abc")
            ->assertOk()
            ->assertJson([
                'total' => 0.1,
                'videos' => [
                    $working->id => ['status' => 'recording', 'working' => true, 'class' => 'working', 'cost' => 0.1, 'length' => null],
                    $done->id => ['status' => 'ready', 'working' => false, 'length' => '01:04'],
                ],
            ]);
    }

    public function test_a_video_being_made_shows_its_progress_and_time_left(): void
    {
        $this->assertNull(Progress::for($this->video()));

        $video = $this->video(['status' => VideoStatus::Queued]);
        $queued = Progress::for($video);
        $this->assertSame(0, $queued['percent']);
        $this->assertSame('Waiting for a queue worker', $queued['stage']);
        $this->assertGreaterThan(60, $queued['remaining']);

        // Further along each stage, and less time left, as time passes.
        $this->travelTo(now());
        $video->moveTo(VideoStatus::Planning);
        $start = Progress::for($video->fresh());
        $this->assertSame(8, $start['percent']);

        $this->travel(20)->seconds();
        $later = Progress::for($video->fresh());
        $this->assertGreaterThan(8, $later['percent']);
        $this->assertLessThan($start['remaining'], $later['remaining']);

        // A stage that runs long stops short of its end, instead of passing 100%.
        $this->travel(2)->hours();
        $this->assertSame(43, Progress::for($video->fresh())['percent']);

        $video->moveTo(VideoStatus::Encoding);
        $encoding = Progress::for($video->fresh());
        $this->assertSame(85, $encoding['percent']);
        $this->assertNull($encoding['stage']);

        $video->moveTo(VideoStatus::Ready);
        $this->assertNull(Progress::for($video->fresh()));
    }

    public function test_the_video_page_shows_the_progress_bar_while_it_is_made(): void
    {
        // The bar moves with time, so the clock stands still for the checks.
        $this->freezeTime();
        $video = $this->video(['status' => VideoStatus::Recording, 'status_at' => now()]);

        $this->get("/video-demo/studio/{$video->id}")->assertOk()->assertSee('id="vd-progress"', false)->assertSee('role="progressbar"', false);
        $this->getJson("/video-demo/studio/{$video->id}/status")->assertOk()->assertJsonPath('progress.percent', 45);

        $video->update(['status' => VideoStatus::Ready]);
        $this->get("/video-demo/studio/{$video->id}")->assertDontSee('id="vd-progress"', false);
        $this->getJson("/video-demo/studio/{$video->id}/status")->assertJsonPath('progress', null);
    }
}
