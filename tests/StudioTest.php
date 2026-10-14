<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoStyle;

class StudioTest extends TestCase
{
    public function test_pages_render(): void
    {
        $video = Video::create([
            'prompt' => 'How to post a job', 'target_seconds' => 60, 'model' => 'claude-opus-5-5',
            'effort' => 'high', 'status' => VideoStatus::Failed, 'error' => 'Step 3 failed: Could not find a visible element.',
        ]);

        $this->get('/video-demo/studio')->assertOk()->assertSee('How to post a job');
        $this->get('/video-demo/studio/create')->assertOk()->assertSee('Estimated Claude cost')->assertSee('Complete with AI')->assertSee('Product showcase');
        $this->get('/video-demo/studio/styles')->assertOk()->assertSee('Detailed tutorial');
        $this->get('/video-demo/studio/create')->assertSee('name="devices[]" value="tv"', false)->assertSee('390×844');
        $this->get("/video-demo/studio/{$video->id}")->assertOk()->assertSee('Could not find a visible element');
    }

    public function test_estimate_returns_a_range(): void
    {
        $this->postJson('/video-demo/studio/estimate', ['model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'target_seconds' => 90])
            ->assertOk()
            ->assertJsonStructure(['low', 'expected', 'high', 'turns']);
    }

    public function test_creating_a_video_queues_generation(): void
    {
        Queue::fake();

        $style = VideoStyle::options()->firstWhere('key', 'quick');

        $this->post('/video-demo/studio', [
            'style_id' => $style->id,
            'devices' => ['mobile', 'desktop'],
            'prompt' => 'How a recruiter posts a new job',
            'target_seconds' => 60,
            'model' => 'claude-opus-5-5',
            'effort' => 'high',
            'visibility' => 'public',
            'start_path' => '/dashboard',
        ])->assertRedirect();

        $video = Video::firstOrFail();
        $this->assertSame('how-a-recruiter-posts-a-new-job', $video->slug);
        $this->assertSame(VideoStatus::Queued, $video->status);
        $this->assertNotNull($video->estimate_high);
        // The video keeps a copy of its style, so editing the style later doesn't change it
        $this->assertSame('Quick tip', $video->style['name']);
        $this->assertSame('brisk', $video->style['pace']);
        // Kept in the configured order, whatever order the form sent
        $this->assertSame(['desktop', 'mobile'], $video->devices);
        Queue::assertPushed(GenerateVideo::class, fn ($job) => $job->video->is($video) && $job->replan);
    }

    public function test_recorder_login_link_signs_in_only_the_demo_user(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        config(['auth.providers.users.model' => User::class]);
        $demo = User::forceCreate(['email' => 'demo@example.com', 'password' => 'x']);
        $other = User::forceCreate(['email' => 'other@example.com', 'password' => 'x']);
        config(['video-demo.recorder.login_as' => 'demo@example.com']);

        $this->get('/video-demo/studio/_recorder/login/'.$demo->id)->assertForbidden();

        $link = URL::temporarySignedRoute('video-demo.studio.recorder-login', now()->addMinutes(5), ['user' => $other->id], absolute: false);
        $this->get($link)->assertForbidden();

        $link = URL::temporarySignedRoute('video-demo.studio.recorder-login', now()->addMinutes(5), ['user' => $demo->id], absolute: false);
        $this->get($link)->assertRedirect('/');
        $this->assertAuthenticatedAs($demo);
    }

    public function test_a_video_needs_at_least_one_known_version(): void
    {
        $style = VideoStyle::options()->first();
        $base = ['style_id' => $style->id, 'prompt' => 'x', 'target_seconds' => 60, 'model' => 'claude-opus-5-5', 'effort' => 'high', 'visibility' => 'public'];

        $this->post('/video-demo/studio', [...$base, 'devices' => []])->assertSessionHasErrors('devices');
        $this->post('/video-demo/studio', [...$base, 'devices' => ['watch']])->assertSessionHasErrors('devices.0');
    }

    public function test_recording_again_can_change_the_versions(): void
    {
        Queue::fake();
        $video = Video::create([
            'prompt' => 'x', 'target_seconds' => 60, 'model' => 'claude-opus-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop'], 'plan' => ['title' => 'x', 'summary' => '', 'steps' => [['action' => 'pause']]],
        ]);

        $this->post("/video-demo/studio/{$video->id}/rerecord", ['devices' => ['tv', 'desktop', 'tablet']])->assertRedirect();

        $this->assertSame(['desktop', 'tv', 'tablet'], $video->fresh()->devices);
        Queue::assertPushed(GenerateVideo::class, fn ($job) => ! $job->replan);
    }
}
