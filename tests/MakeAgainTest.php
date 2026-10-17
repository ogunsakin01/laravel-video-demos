<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Studio\Embeds;
use Ogunsakin01\VideoDemo\VideoDemo;
use RuntimeException;

/** The "Make it again" form, and deleting videos with a warning where they are used. */
class MakeAgainTest extends TestCase
{
    protected function video(array $overrides = []): Video
    {
        return Video::create([
            'title' => 'Post a job', 'prompt' => 'How a recruiter posts a job', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop'], 'style' => VideoStyle::options()->firstWhere('key', 'clean')->snapshot(),
            'plan' => ['title' => 'x', 'summary' => '', 'steps' => [['action' => 'goto', 'url' => '/jobs']]],
            ...$overrides,
        ]);
    }

    protected function form(Video $video, array $changes = []): array
    {
        return [
            'prompt' => $video->prompt, 'target_seconds' => $video->target_seconds, 'speed' => 2, 'devices' => ['desktop'],
            'record_as' => '', 'start_path' => '', 'sample_data' => '0', 'model' => $video->model, 'effort' => $video->effort,
            'max_cost' => '0.50', 'max_steps' => '', 'new_plan' => '0', ...$changes,
        ];
    }

    public function test_one_form_records_the_same_plan_again_or_asks_for_a_new_one(): void
    {
        Queue::fake();
        $video = $this->video();
        $spotlight = VideoStyle::options()->firstWhere('key', 'spotlight');

        $page = $this->get("/video-demo/studio/{$video->id}")->assertOk();
        $page->assertSee('Ask the AI for a new plan')->assertSee('Record it again (free)')->assertDontSee('/rerecord', false);

        // Toggle off: the same plan, free, with the new style's look, versions and speed.
        $this->post("/video-demo/studio/{$video->id}/again", $this->form($video, ['style_id' => $spotlight->id, 'devices' => ['desktop', 'mobile'], 'speed' => 1.5]))->assertRedirect();
        $video->refresh();
        $this->assertSame(['Spotlight', ['desktop', 'mobile'], 1.5], [$video->style['name'], $video->devices, $video->playbackSpeed()]);
        $this->assertSame('Queued to record the same plan again', collect($video->log)->last()['message']);
        Queue::assertPushed(GenerateVideo::class, fn ($job) => ! $job->replan);

        // Toggle on: a new plan, with the new description and length.
        $video->refresh()->update(['status' => VideoStatus::Ready]);
        $this->post("/video-demo/studio/{$video->id}/again", $this->form($video, ['prompt' => 'How a recruiter edits a job', 'target_seconds' => 45, 'new_plan' => '1']))->assertRedirect();
        $this->assertSame(['How a recruiter edits a job', 45], [$video->fresh()->prompt, $video->fresh()->target_seconds]);
        Queue::assertPushed(GenerateVideo::class, fn ($job) => $job->replan);
    }

    public function test_trying_a_stopped_video_again_uses_the_changes_made_to_it(): void
    {
        Queue::fake();
        $video = $this->video(['status' => VideoStatus::Stopped, 'stop_requested' => true]);

        // "Try again" sends the "Make it again" form, so what was changed there is used.
        $page = $this->get("/video-demo/studio/{$video->id}")->assertOk();
        $page->assertSee('form="vd-again" formaction="'.route('video-demo.studio.again', [$video, 'plan' => 'new']).'"', false)
            ->assertSee('form="vd-again" formaction="'.route('video-demo.studio.again', [$video, 'plan' => 'same']).'"', false)
            ->assertDontSee(route('video-demo.studio.retry', $video), false);

        // A new plan, whatever the switch says.
        $this->post(route('video-demo.studio.again', [$video, 'plan' => 'new']), $this->form($video, ['prompt' => 'How a recruiter closes a job', 'target_seconds' => 45]))
            ->assertRedirect(route('video-demo.studio.show', $video));
        $video->refresh();
        $this->assertSame(['How a recruiter closes a job', 45, VideoStatus::Queued, false], [$video->prompt, $video->target_seconds, $video->status, $video->stop_requested]);
        Queue::assertPushed(GenerateVideo::class, fn ($job) => $job->replan === true);

        // The same plan, with the new length and speed.
        $video->update(['status' => VideoStatus::Stopped]);
        $this->post(route('video-demo.studio.again', [$video, 'plan' => 'same']), $this->form($video, ['target_seconds' => 60, 'new_plan' => '1']));
        $this->assertSame([60, 'Queued to record the same plan again'], [$video->fresh()->target_seconds, collect($video->fresh()->log)->last()['message']]);
    }

    public function test_a_star_on_github_is_asked_for_once_a_video_is_published(): void
    {
        $video = $this->video();

        // Not before anything is published.
        $this->get('/video-demo/studio')->assertOk()->assertDontSee('data-star-note', false);
        $this->get("/video-demo/studio/{$video->id}")->assertDontSee('data-star-note', false);

        $video->update(['published_at' => now()]);
        $this->get('/video-demo/studio')->assertSee('a star on GitHub helps other Laravel developers find it')
            ->assertSee('href="'.VideoDemo::GITHUB_URL.'"', false);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('data-star-note', false);
    }

    public function test_a_new_user_or_data_or_no_plan_always_means_a_new_plan(): void
    {
        Queue::fake();
        $video = $this->video();

        $this->post("/video-demo/studio/{$video->id}/again", $this->form($video, ['sample_data' => '1']))->assertRedirect();
        $this->assertTrue($video->fresh()->sample_data);
        $this->assertStringContainsString('the user, the data or the language changed', collect($video->fresh()->log)->last()['message']);
        Queue::assertPushed(GenerateVideo::class, fn ($job) => $job->replan);

        Queue::fake();
        $planless = $this->video(['plan' => null]);
        $this->get("/video-demo/studio/{$planless->id}")->assertSee('There is no plan yet, so the AI makes one');
        $this->post("/video-demo/studio/{$planless->id}/again", $this->form($planless))->assertRedirect();
        Queue::assertPushed(GenerateVideo::class, fn ($job) => $job->replan);

        $planless->refresh()->update(['status' => VideoStatus::Ready]);
        $this->post("/video-demo/studio/{$planless->id}/again", $this->form($planless, ['devices' => []]))->assertSessionHasErrors('devices');
    }

    public function test_where_a_video_is_shown_is_found_in_the_code(): void
    {
        $file = resource_path('views/video-demo-embed-test.blade.php');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, "<h1>Help</h1>\n<x-video-demo::player slug=\"post-a-job\" device=\"mobile\" />\n<p>post-a-job is a job, not a video here</p>\n<video src=\"/storage/video-demo/post-a-job-v3-desktop.mp4\"></video>\n");

        try {
            $found = Embeds::find(['post-a-job', 'run-payroll'])['post-a-job'];
            $this->assertSame([2, 4], array_column($found, 'line'));
            $this->assertStringEndsWith('views/video-demo-embed-test.blade.php', $found[0]['file']);
            $this->assertSame([], Embeds::find(['run-payroll'])['run-payroll']);
        } finally {
            File::delete($file);
        }
    }

    public function test_deleting_says_where_a_video_is_used_first(): void
    {
        Storage::fake('public');
        $video = $this->video(['slug' => 'post-a-job', 'published_at' => now()]);
        app(Manifest::class)->put($this->manifestVideo());
        $quiet = $this->video(['title' => 'Run payroll', 'slug' => 'run-payroll']);
        $busy = $this->video(['title' => 'Busy', 'slug' => 'busy', 'status' => VideoStatus::Recording]);
        $file = resource_path('views/video-demo-embed-test.blade.php');
        File::put($file, "<x-video-demo::player slug=\"post-a-job\" />\n");

        try {
            $this->get("/video-demo/studio/{$video->id}")->assertSee('/video-demo/studio/delete?videos', false);

            $this->get('/video-demo/studio/delete?'.http_build_query(['videos' => [$video->id, $quiet->id, $busy->id]]))
                ->assertOk()
                ->assertSee('Delete these 3 videos?')
                ->assertSee('1 of them is in use.')
                ->assertSee('Published in the public library')
                ->assertSee('views/video-demo-embed-test.blade.php:1')
                ->assertSee('Not published, and not shown on any page in your code.')
                ->assertSee('Still being made, so it will be kept.');

            // An id deleted in another tab is not counted as kept.
            $this->post('/video-demo/studio/bulk', ['action' => 'delete', 'videos' => [$video->id, $quiet->id, $busy->id, 999_999]])
                ->assertRedirect('/video-demo/studio')
                ->assertSessionHas('status', 'Deleted 2 videos. 1 still being made was kept; stop them first.');
            $this->assertSame(['Busy'], Video::pluck('title')->all());
            // Taken out of the library too.
            $this->assertNull(app(Manifest::class)->find('post-a-job', 'private'));
        } finally {
            File::delete($file);
        }

        $this->get('/video-demo/studio/delete')->assertRedirect('/video-demo/studio');

        $this->expectException(RuntimeException::class);
        app(VideoDemo::class)->delete($busy);
    }
}
