<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoIdea;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Recording\RecordAs;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use RuntimeException;

/** Spending limits, step limits, Stop, who a video is recorded as, embed code and the queue */
class ControlsTest extends TestCase
{
    protected function video(array $overrides = []): Video
    {
        return Video::create([
            'prompt' => 'How to post a job', 'target_seconds' => 60, 'model' => 'claude-sonnet-5-5',
            'effort' => 'medium', 'status' => VideoStatus::Planning, ...$overrides,
        ]);
    }

    protected function users(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        config(['auth.providers.users.model' => User::class]);
        User::forceCreate(['email' => 'admin@example.com', 'password' => 'x']);
        User::forceCreate(['email' => 'member@example.com', 'password' => 'x']);
    }

    public function test_prices_match_the_published_rates(): void
    {
        // Per million tokens: Sonnet 5.5 is $2 in, $2.50 to write the cache, $0.10 to read it, $10 out
        $this->assertEqualsWithDelta(14.60, Pricing::cost('claude-sonnet-5-5', 1_000_000, 1_000_000, 1_000_000, 1_000_000), 0.0001);
        // An hour-long cache write costs twice the input price
        $this->assertEqualsWithDelta(4.0, Pricing::cost('claude-sonnet-5-5', 0, 0, 0, 0, 1_000_000), 0.0001);
        // Haiku 5.5 is $0.10 in and $0.50 out, five times that once the prompt passes 100K tokens
        $this->assertEqualsWithDelta(0.10 * 0.05 + 0.50 * 0.001, Pricing::cost('claude-haiku-5-5', 50_000, 0, 0, 1_000), 0.000001);
        $this->assertEqualsWithDelta(5 * (0.10 * 0.06 + 0.01 * 0.05 + 0.50 * 0.001), Pricing::cost('claude-haiku-5-5', 60_000, 0, 50_000, 1_000), 0.000001);
    }

    public function test_the_default_model_is_the_cheapest_reliable_one(): void
    {
        config(['video-demo.ai.model' => null]);
        $this->assertSame('claude-sonnet-5-5', Pricing::defaultModel());
        $this->assertMatchesRegularExpression('/<option value="claude-sonnet-5-5"[^>]*selected/', $this->get('/video-demo/studio/create')->getContent());

        // With only an OpenAI key, the cheapest reliable OpenAI model
        config([
            'video-demo.ai.api_key' => null,
            'prism.providers.openai.api_key' => 'sk-test',
            'video-demo.ai.models' => [...config('video-demo.ai.models'), 'gpt-6.1-sol' => ['label' => 'GPT-6.1 Sol', 'provider' => 'openai', 'reliable' => true, 'input' => 2.00, 'output' => 10.00]],
        ]);
        $this->assertSame('gpt-6.1-sol', Pricing::defaultModel());
        $this->get('/video-demo/studio/create')->assertDontSee('to generate videos.')->assertSee('GPT-6.1 Sol')->assertDontSee('Claude Opus 5.5');

        // A model set in the config wins
        config(['video-demo.ai.model' => 'claude-opus-5-5']);
        $this->assertSame('claude-opus-5-5', Pricing::defaultModel());
    }

    public function test_a_video_keeps_its_spending_limit_step_limit_and_user(): void
    {
        Queue::fake();
        $this->users();
        $idea = VideoIdea::create(['title' => 'Post a job', 'prompt' => 'How to post a job', 'target_seconds' => 60, 'style' => 'quick']);

        $this->get('/video-demo/studio/create')->assertSee('Guest (signed out)')->assertSee('member@example.com')->assertSee('Spending limit');

        $this->post('/video-demo/studio', [
            'style_id' => VideoStyle::options()->first()->id,
            'devices' => ['desktop'],
            'prompt' => 'How to post a job',
            'target_seconds' => 60,
            'model' => 'claude-sonnet-5-5',
            'effort' => 'medium',
            'visibility' => 'public',
            'max_cost' => '0.75',
            'max_steps' => '12',
            'record_as' => '2',
            'idea' => $idea->id,
        ])->assertRedirect();

        $video = Video::firstOrFail();
        $this->assertSame(0.75, $video->spendLimit());
        $this->assertSame(12, $video->max_steps);
        $this->assertSame('2', $video->record_as);
        $this->assertSame('member@example.com', $video->record_as_label);
        $this->assertSame($video->id, $idea->fresh()->video_id);
        Queue::assertPushed(GenerateVideo::class, fn ($job) => $job->connection === VideoDemoServiceProvider::QUEUE_CONNECTION && $job->queue === 'video-demo');

        $this->get("/video-demo/studio/{$video->id}")->assertSee('$0.75')->assertSee('member@example.com');

        // An unknown user is refused
        $this->post('/video-demo/studio', ['record_as' => '99'])->assertSessionHasErrors('record_as');
    }

    public function test_record_as_finds_users_and_guests(): void
    {
        $this->users();
        config(['video-demo.recorder.login_as' => 'admin@example.com']);

        $options = RecordAs::options();
        $this->assertSame('The default user (admin@example.com)', $options['']);
        $this->assertSame('Guest (signed out)', $options[RecordAs::GUEST]);
        $this->assertSame('member@example.com', $options['2']);

        $this->assertNull(RecordAs::resolve(RecordAs::GUEST));
        $this->assertSame('admin@example.com', RecordAs::resolve(null)->email);
        $this->assertSame('member@example.com', RecordAs::resolve('member@example.com')->email);
        $this->expectException(RuntimeException::class);
        RecordAs::resolve('nobody@example.com');
    }

    public function test_stop_ends_a_queued_video_at_once_and_a_running_one_at_its_next_step(): void
    {
        Queue::fake();

        $queued = $this->video(['status' => VideoStatus::Queued]);
        $this->post("/video-demo/studio/{$queued->id}/stop")->assertRedirect();
        $this->assertSame(VideoStatus::Stopped, $queued->fresh()->status);

        $running = $this->video(['status' => VideoStatus::Planning]);
        $this->get("/video-demo/studio/{$running->id}")->assertSee('>Stop</button>', false);
        $this->post("/video-demo/studio/{$running->id}/stop");
        $this->assertTrue($running->fresh()->stop_requested);
        $this->getJson("/video-demo/studio/{$running->id}/status")->assertJson(['stopping' => true, 'working' => true]);

        // A stopped video can be tried again, and the old Stop is forgotten
        $running->update(['status' => VideoStatus::Stopped]);
        $this->get("/video-demo/studio/{$running->id}")->assertSee('Try again');
        $this->post("/video-demo/studio/{$running->id}/retry", ['mode' => 'plan'])->assertRedirect();
        $this->assertFalse($running->fresh()->stop_requested);
        $this->assertSame(VideoStatus::Queued, $running->fresh()->status);
        Queue::assertPushed(GenerateVideo::class, 1);
    }

    public function test_a_job_for_a_stopped_video_spends_nothing(): void
    {
        $this->mock(Planner::class)->shouldNotReceive('plan');
        $video = $this->video(['status' => VideoStatus::Queued, 'stop_requested' => true]);

        GenerateVideo::dispatchSync($video);

        $video->refresh();
        $this->assertSame(VideoStatus::Stopped, $video->status);
        $this->assertFalse($video->stop_requested);
        $this->assertSame(0.0, $video->cost);
    }

    public function test_page_problems_are_listed_on_the_video(): void
    {
        $video = $this->video(['status' => VideoStatus::Failed, 'error' => "A page isn't working", 'issues' => [
            ['path' => '/reports', 'problem' => 'The page returned HTTP 500', 'status' => 500, 'url' => null, 'detail' => 'Undefined variable $total'],
        ]]);

        $this->get("/video-demo/studio/{$video->id}")
            ->assertSee('A page in your app has a problem')
            ->assertSee('/reports')
            ->assertSee('Undefined variable $total');
        $this->getJson("/video-demo/studio/{$video->id}/status")->assertJsonPath('issues.0.path', '/reports');
    }

    public function test_published_videos_show_embed_code(): void
    {
        Storage::fake('public');
        app(Manifest::class)->put($this->manifestVideo());
        $video = $this->video(['title' => 'Post a job', 'slug' => 'post-a-job', 'status' => VideoStatus::Ready, 'published_at' => now()]);

        $this->get("/video-demo/studio/{$video->id}")
            ->assertSee('&lt;x-video-demo::player slug=&quot;post-a-job&quot; /&gt;', false)
            ->assertSee('&lt;video src=&quot;http://', false)
            ->assertSee('/storage/video-demo/post-a-job-v1.mp4&quot; poster=', false)
            ->assertSee('data-copy=', false);

        // And on the staff library page, not the public one
        $this->get('/admin/videos/post-a-job')->assertSee('Embed');
        $this->get('/help/videos/post-a-job')->assertDontSee('data-copy=', false);
    }

    public function test_local_library_addresses_work_at_any_host(): void
    {
        config([
            'app.url' => 'http://old-name.test',
            'filesystems.disks.public' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => 'http://old-name.test/storage'],
        ]);

        $this->assertSame('/storage/video-demo/a.mp4', app(Manifest::class)->url(['video' => 'video-demo/a.mp4']));
        // A disk on another host, such as a CDN or S3, keeps its full address
        $this->assertSame('https://cdn.example.com/a.mp4', Manifest::relative('https://cdn.example.com/a.mp4', 'public'));
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $this->assertSame('http://old-name.test/a.mp4', Manifest::relative('http://old-name.test/a.mp4', 's3'));
    }

    public function test_long_videos_get_their_own_queue_connection(): void
    {
        $connection = config('queue.connections.'.VideoDemoServiceProvider::QUEUE_CONNECTION);
        $this->assertSame(7200, $connection['retry_after']);
        $this->assertSame('video-demo', $connection['queue']);

        // A sync queue holds the page open while a video is made
        config(['video-demo.queue.connection' => 'sync']);
        $this->assertStringContainsString('QUEUE_CONNECTION=sync', VideoDemoServiceProvider::queueWarning());

        config([
            'queue.connections.short' => ['driver' => 'database', 'retry_after' => 90],
            'video-demo.queue.connection' => 'short',
        ]);
        $this->assertStringContainsString('retry_after=90', VideoDemoServiceProvider::queueWarning());
        $this->assertStringContainsString('retry_after=90', $this->get('/video-demo/studio/create')->getContent());
    }
}
