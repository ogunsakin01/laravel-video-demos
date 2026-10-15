<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Closure;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Studio\Pages;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;

/**
 * Tests spending limits, step limits, Stop, who a video is recorded as, embed
 * code and the queue.
 */
class ControlsTest extends TestCase
{
    protected function video(array $overrides = []): Video
    {
        return Video::create([
            'prompt' => 'How to post a job', 'target_seconds' => 60, 'model' => 'claude-sonnet-5-5',
            'effort' => 'medium', 'status' => VideoStatus::Planning, ...$overrides,
        ]);
    }

    /**
     * Create a users table with a role column: one admin and two members.
     */
    protected function users(?Closure $columns = null): void
    {
        Schema::create('users', function ($table) use ($columns) {
            $table->id();
            $table->string('email');
            $table->string('password');
            $columns ? $columns($table) : $table->string('role')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        config(['auth.providers.users.model' => User::class]);
        if (! $columns) {
            User::forceCreate(['email' => 'admin@example.com', 'password' => 'x', 'role' => 'admin']);
            User::forceCreate(['email' => 'member@example.com', 'password' => 'x', 'role' => 'member']);
            User::forceCreate(['email' => 'second-member@example.com', 'password' => 'x', 'role' => 'member']);
        }
    }

    public function test_prices_match_the_published_rates(): void
    {
        // Per million tokens: Sonnet 5.5 is $2 in, $2.50 to write the cache, $0.10 to read it, $10 out.
        $this->assertEqualsWithDelta(14.60, Pricing::cost('claude-sonnet-5-5', 1_000_000, 1_000_000, 1_000_000, 1_000_000), 0.0001);
        // An hour-long cache write costs twice the input price.
        $this->assertEqualsWithDelta(4.0, Pricing::cost('claude-sonnet-5-5', 0, 0, 0, 0, 1_000_000), 0.0001);
        // Haiku 5.5 is $0.10 in and $0.50 out, five times that once the prompt passes 100K tokens.
        $this->assertEqualsWithDelta(0.10 * 0.05 + 0.50 * 0.001, Pricing::cost('claude-haiku-5-5', 50_000, 0, 0, 1_000), 0.000001);
        $this->assertEqualsWithDelta(5 * (0.10 * 0.06 + 0.01 * 0.05 + 0.50 * 0.001), Pricing::cost('claude-haiku-5-5', 60_000, 0, 50_000, 1_000), 0.000001);
    }

    public function test_the_default_model_is_the_cheapest_reliable_one(): void
    {
        config(['video-demo.ai.model' => null]);
        $this->assertSame('claude-sonnet-5-5', Pricing::defaultModel());
        $this->assertMatchesRegularExpression('/<option value="claude-sonnet-5-5"[^>]*selected/', $this->get('/video-demo/studio/create')->getContent());

        // With only an OpenAI key, the cheapest reliable OpenAI model is picked.
        config([
            'video-demo.ai.api_key' => null,
            'prism.providers.openai.api_key' => 'sk-test',
            'video-demo.ai.models' => [...config('video-demo.ai.models'), 'gpt-6.1-sol' => ['label' => 'GPT-6.1 Sol', 'provider' => 'openai', 'reliable' => true, 'input' => 2.00, 'output' => 10.00]],
        ]);
        $this->assertSame('gpt-6.1-sol', Pricing::defaultModel());
        $this->get('/video-demo/studio/create')->assertDontSee('to generate videos.')->assertSee('GPT-6.1 Sol')->assertDontSee('Claude Opus 5.5');

        // A model set in the config wins.
        config(['video-demo.ai.model' => 'claude-opus-5-5']);
        $this->assertSame('claude-opus-5-5', Pricing::defaultModel());
    }

    public function test_a_video_keeps_its_spending_limit_step_limit_and_user(): void
    {
        Queue::fake();
        $this->users();
        $idea = VideoIdea::create(['title' => 'Post a job', 'prompt' => 'How to post a job', 'target_seconds' => 60, 'style' => 'quick']);

        // The list shows user types, not users.
        $this->get('/video-demo/studio/create')->assertSee('Guest (signed out)')->assertSee('value="type:member"', false)->assertDontSee('member@example.com')->assertSee('Spending limit');

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
            'record_as' => 'type:member',
            'idea' => $idea->id,
        ])->assertRedirect();

        $video = Video::firstOrFail();
        $this->assertSame(0.75, $video->spendLimit());
        $this->assertSame(12, $video->max_steps);
        $this->assertSame('type:member', $video->record_as);
        $this->assertSame('Member', $video->record_as_label);
        $this->assertSame($video->id, $idea->fresh()->video_id);
        Queue::assertPushed(GenerateVideo::class, fn ($job) => $job->connection === VideoDemoServiceProvider::QUEUE_CONNECTION && $job->queue === 'video-demo');

        $this->get("/video-demo/studio/{$video->id}")->assertSee('$0.75')->assertSee('Member');

        // An unknown type is refused.
        $this->post('/video-demo/studio', ['record_as' => 'type:owner'])->assertSessionHasErrors('record_as');
    }

    public function test_record_as_offers_the_apps_user_types(): void
    {
        $this->users();
        config(['video-demo.recorder.login_as' => 'second-member@example.com']);

        $this->assertSame([
            '' => 'The default user (second-member@example.com)',
            'guest' => 'Guest (signed out)',
            'type:admin' => 'Admin',
            'type:member' => 'Member',
        ], RecordAs::options());

        $this->assertNull(RecordAs::resolve(RecordAs::GUEST));
        $this->assertSame('admin@example.com', RecordAs::resolve('type:admin')->email);
        // The default user is used when they are of that type.
        $this->assertSame('second-member@example.com', RecordAs::resolve('type:member')->email);
        // Videos from 0.4.0 stored a user.
        $this->assertSame('member@example.com', RecordAs::resolve('member@example.com')->email);

        // The command takes a type's key or label.
        $this->assertSame('type:admin', RecordAs::parse('Admin'));
        $this->assertSame('guest', RecordAs::parse('guest'));

        $this->expectExceptionMessage('The user type [owner] wasn');
        RecordAs::resolve('type:owner');
    }

    public function test_user_types_come_from_a_roles_table(): void
    {
        $this->users(fn ($t) => $t->foreignId('role_id')->nullable());
        Schema::create('roles', function ($t) {
            $t->id();
            $t->string('name');
        });
        DB::table('roles')->insert([['name' => 'editor'], ['name' => 'viewer']]);
        User::forceCreate(['email' => 'ed@example.com', 'password' => 'x', 'role_id' => 1]);
        User::forceCreate(['email' => 'vi@example.com', 'password' => 'x', 'role_id' => 2]);

        $this->assertSame(['type:editor' => 'Editor', 'type:viewer' => 'Viewer'], array_slice(RecordAs::options(), 1));
        $this->assertSame('vi@example.com', RecordAs::resolve('type:viewer')->email);
    }

    public function test_user_types_come_from_a_pivot_table(): void
    {
        $this->users(fn ($t) => null);
        Schema::create('roles', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('display_name')->nullable();
        });
        Schema::create('role_user', function ($t) {
            $t->foreignId('role_id');
            $t->foreignId('user_id');
        });
        DB::table('roles')->insert([['name' => 'owner', 'display_name' => 'Shop owner'], ['name' => 'staff', 'display_name' => null]]);
        User::forceCreate(['email' => 'a@example.com', 'password' => 'x']);
        User::forceCreate(['email' => 'b@example.com', 'password' => 'x']);
        DB::table('role_user')->insert([['role_id' => 1, 'user_id' => 2], ['role_id' => 2, 'user_id' => 1]]);

        $this->assertSame(['type:owner' => 'Shop owner', 'type:staff' => 'Staff'], array_slice(RecordAs::options(), 1));
        $this->assertSame('b@example.com', RecordAs::resolve('type:owner')->email);

        // Someone with both roles isn't picked for either while a user with
        // just that role exists, even when they are the default user.
        User::forceCreate(['email' => 'both@example.com', 'password' => 'x']);
        DB::table('role_user')->insert([['role_id' => 1, 'user_id' => 3], ['role_id' => 2, 'user_id' => 3]]);
        config(['video-demo.recorder.login_as' => 'both@example.com']);
        $this->assertSame('a@example.com', RecordAs::resolve('type:staff')->email);
        $this->assertSame('b@example.com', RecordAs::resolve('type:owner')->email);
    }

    public function test_user_types_come_from_an_admin_flag_or_the_config(): void
    {
        $this->users(fn ($t) => $t->boolean('is_admin')->default(false));
        User::forceCreate(['email' => 'boss@example.com', 'password' => 'x', 'is_admin' => true]);
        User::forceCreate(['email' => 'jo@example.com', 'password' => 'x']);

        $this->assertSame(['type:admin' => 'Admin', 'type:user' => 'Other users'], array_slice(RecordAs::options(), 1));
        $this->assertSame('jo@example.com', RecordAs::resolve('type:user')->email);

        // Listing them in the config wins.
        config(['video-demo.recorder.user_types' => ['Account owner' => 'boss@example.com']]);
        $this->assertSame(['type:account-owner' => 'Account owner'], array_slice(RecordAs::options(), 1));
        $this->assertSame('boss@example.com', RecordAs::resolve('type:account-owner')->email);
    }

    public function test_a_type_without_users_explains_itself(): void
    {
        $this->users(fn ($t) => $t->string('role')->nullable());
        config(['video-demo.recorder.user_types' => ['Auditor' => 'missing@example.com']]);

        $this->expectExceptionMessage('missing@example.com');
        RecordAs::resolve('type:auditor');
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

        // A stopped video can be tried again, and the old Stop is forgotten.
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

        // And on the staff library page, not the public one.
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
        // A disk on another host, such as a CDN or S3, keeps its full address.
        $this->assertSame('https://cdn.example.com/a.mp4', Manifest::relative('https://cdn.example.com/a.mp4', 'public'));
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $this->assertSame('http://old-name.test/a.mp4', Manifest::relative('http://old-name.test/a.mp4', 's3'));
    }

    public function test_long_videos_get_their_own_queue_connection(): void
    {
        $connection = config('queue.connections.'.VideoDemoServiceProvider::QUEUE_CONNECTION);
        $this->assertSame(7200, $connection['retry_after']);
        $this->assertSame('video-demo', $connection['queue']);

        // A sync queue holds the page open while a video is made.
        config(['video-demo.queue.connection' => 'sync']);
        $this->assertStringContainsString('QUEUE_CONNECTION=sync', VideoDemoServiceProvider::queueWarning());

        config([
            'queue.connections.short' => ['driver' => 'database', 'retry_after' => 90],
            'video-demo.queue.connection' => 'short',
        ]);
        $this->assertStringContainsString('retry_after=90', VideoDemoServiceProvider::queueWarning());
        $this->assertStringContainsString('retry_after=90', $this->get('/video-demo/studio/create')->getContent());
    }

    public function test_bulk_publish_and_unpublish(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $make = function (string $title, VideoStatus $status) {
            $video = $this->video(['title' => $title, 'status' => $status, 'version' => 1, 'devices' => ['desktop'],
                'recordings' => $status === VideoStatus::Ready ? ['desktop' => ['seconds' => 20.0, 'chapters' => []]] : null,
                'plan' => ['title' => $title, 'summary' => '', 'steps' => []]]);
            if ($status === VideoStatus::Ready) {
                Storage::disk('local')->put($video->workFile('desktop'), 'mp4');
                Storage::disk('local')->put($video->workFile('desktop', 'poster'), 'jpg');
            }

            return $video;
        };
        $a = $make('Post a job', VideoStatus::Ready);
        $b = $make('Invite a teammate', VideoStatus::Ready);
        $c = $make('Run payroll', VideoStatus::Failed);

        $this->get('/video-demo/studio')->assertSee('id="vd-select-all"', false)->assertSee('name="videos[]"', false);

        $this->post('/video-demo/studio/bulk', ['action' => 'publish', 'videos' => [$a->id, $b->id, $c->id]])
            ->assertRedirect('/video-demo/studio')
            ->assertSessionHas('status', 'Published 2 videos. Skipped 1: not ready, or already published at their latest version.');
        $this->assertTrue($a->fresh()->isPublished() && $b->fresh()->isPublished());
        $this->assertNotNull(app(Manifest::class)->find('invite-a-teammate', 'public'));

        // Already live at this version: nothing to do.
        $this->post('/video-demo/studio/bulk', ['action' => 'publish', 'videos' => [$a->id]])->assertSessionHas('status', 'Published 0 videos. Skipped 1: not ready, or already published at their latest version.');

        $this->post('/video-demo/studio/bulk', ['action' => 'unpublish', 'videos' => [$a->id, $c->id]])->assertSessionHas('status', 'Unpublished 1 video. Skipped 1: not published.');
        $this->assertFalse($a->fresh()->isPublished());
        $this->assertNull(app(Manifest::class)->find('post-a-job', 'public'));

        $this->post('/video-demo/studio/bulk', ['action' => 'publish'])->assertSessionHasErrors('videos');
    }

    public function test_start_pages_are_get_routes_without_parameters(): void
    {
        Route::get('/dashboard', fn () => 'x')->name('dashboard');
        Route::get('/jobs/{job}', fn () => 'x');
        Route::get('/reports/{year?}', fn () => 'x');
        Route::post('/jobs', fn () => 'x');
        Route::get('/logout', fn () => 'x')->name('logout');
        Route::get('/api/users', fn () => 'x');
        Route::get('/sitemap.xml', fn () => 'x');
        Route::get('/invoices/export', fn () => 'x');
        Route::get('/verify', fn () => 'x')->middleware('signed');
        app('router')->getRoutes()->refreshNameLookups();

        $paths = array_keys(app(Pages::class)->candidates());

        $this->assertContains('/dashboard', $paths);
        $this->assertContains('/reports', $paths);
        $this->assertContains('/help/videos', $paths);
        foreach (['/jobs/{job}', '/jobs', '/logout', '/api/users', '/sitemap.xml', '/invoices/export', '/verify', '/video-demo/studio'] as $left) {
            $this->assertNotContains($left, $paths);
        }
    }

    public function test_each_video_can_have_its_own_speed(): void
    {
        Queue::fake();
        config(['video-demo.recorder.speed' => 2]);

        // The configured speed is the default, and any speed is kept between 0.25 and 4.
        $this->assertSame(2.0, Recorder::speed());
        $this->assertSame(1.2, Recorder::speed(1.2));
        $this->assertSame(4.0, Recorder::speed(9));

        $this->get('/video-demo/studio/create')->assertSee('Playback speed')->assertSee('name="speed" value="2"', false)->assertSee('data-speed="1"', false);

        $this->post('/video-demo/studio', [
            'style_id' => VideoStyle::options()->first()->id, 'devices' => ['desktop'], 'prompt' => 'How to post a job',
            'target_seconds' => 60, 'model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'visibility' => 'public', 'speed' => '1.25',
        ])->assertRedirect();
        $video = Video::firstOrFail();
        $this->assertSame(1.25, $video->playbackSpeed());
        $this->get("/video-demo/studio/{$video->id}")->assertSee('1.25×');

        // Recording the same plan again can change the speed, at no AI cost.
        $video->update(['status' => VideoStatus::Ready, 'plan' => ['title' => 'x', 'summary' => '', 'steps' => [['action' => 'goto', 'url' => '/']]]]);
        $this->post("/video-demo/studio/{$video->id}/rerecord", ['devices' => ['desktop'], 'speed' => '1'])->assertRedirect();
        $this->assertSame(1.0, $video->fresh()->playbackSpeed());
        Queue::assertPushed(GenerateVideo::class, fn ($job) => ! $job->replan);

        // A video without its own speed uses the configured one.
        $this->assertSame(2.0, $this->video()->playbackSpeed());
        $this->post('/video-demo/studio', ['speed' => '7'])->assertSessionHasErrors('speed');
    }
}
