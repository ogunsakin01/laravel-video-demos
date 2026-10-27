<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Facades\VideoDemo;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;

/**
 * Editing a video's plan by hand on its studio page, and recording it again
 * for free.
 */
class PlanEditorTest extends TestCase
{
    use FakesAnthropic;

    protected function video(array $overrides = []): Video
    {
        return Video::create([
            'title' => 'Post a job', 'prompt' => 'How a recruiter posts a job', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop', 'mobile'], 'version' => 1,
            'plan' => ['title' => 'Post a job', 'summary' => 'From the jobs page to a live listing.', 'chapters' => [['title' => 'Start', 'at' => 0]], 'steps' => [
                ['action' => 'card', 'title' => 'Post a job', 'text' => null, 'why' => 'Open'],
                ['action' => 'goto', 'url' => '/jobs/create', 'chapter' => 'Start'],
                ['action' => 'type', 'target' => ['role' => null, 'name' => null, 'label' => 'Job title', 'placeholder' => null, 'text' => null, 'selector' => null, 'frame' => null], 'value' => 'Designer', 'say' => 'Give it a clear title.', 'voice_ms' => 2100],
                ['action' => 'caption', 'title' => 'Publsh it', 'text' => 'One click and it is live.'],
            ]],
            ...$overrides,
        ]);
    }

    /**
     * The form's fields as the page sends them, from the stored steps.
     */
    protected function fields(array $steps): array
    {
        return array_map(fn ($s) => array_map(fn ($v) => $v ?? '', array_intersect_key($s, array_flip(['action', 'why', 'url', 'target', 'value', 'title', 'text', 'ms', 'chapter', 'say', 'only_on']))), $steps);
    }

    public function test_the_video_page_shows_the_plan_as_a_form(): void
    {
        $video = $this->video();

        $this->get("/video-demo/studio/{$video->id}")
            ->assertSee('Plan · 4 steps')
            ->assertSee('name="steps[3][title]" value="Publsh it"', false)
            ->assertSee('name="steps[2][say]" value="Give it a clear title."', false)
            ->assertSee('name="steps[2][target][label]" value="Job title"', false)
            ->assertSee('Save and record it again (free)')
            ->assertSee('data-step-template="caption"', false);

        // While it is being made, the plan is only shown.
        $video->update(['status' => VideoStatus::Recording]);
        $this->get("/video-demo/studio/{$video->id}")->assertDontSee('Save and record it again (free)')->assertSee('“Publsh it”', false);
    }

    public function test_an_edited_plan_is_saved_and_recorded_again_without_the_ai(): void
    {
        Queue::fake();
        $video = $this->video();
        $steps = $this->fields($video->plan['steps']);
        // Fix the typo, drop the card, and add a closing card.
        $steps[3]['title'] = 'Publish it';
        array_shift($steps);
        $steps[] = ['action' => 'card', 'why' => '', 'title' => 'That is it', 'text' => '', 'say' => '', 'ms' => '2500'];

        $this->put("/video-demo/studio/{$video->id}/plan", ['title' => 'Post your first job', 'summary' => 'Posting a job.', 'steps' => $steps, 'then' => 'record'])
            ->assertRedirect("/video-demo/studio/{$video->id}");

        $plan = $video->fresh()->plan;
        $this->assertSame('Post your first job', $plan['title']);
        $this->assertSame(['goto', 'type', 'caption', 'card'], array_column($plan['steps'], 'action'));
        $this->assertSame('Publish it', $plan['steps'][2]['title']);
        $this->assertSame(2500, $plan['steps'][3]['ms']);
        $this->assertSame('Start', $plan['steps'][0]['chapter']);
        $this->assertSame('Job title', $plan['steps'][1]['target']['label']);
        // A spoken line's length is worked out again when it is recorded.
        $this->assertArrayNotHasKey('voice_ms', $plan['steps'][1]);
        $this->assertNotNull($video->fresh()->plan_edited_at);
        $this->assertSame(VideoStatus::Queued, $video->fresh()->status);

        Queue::assertPushed(GenerateVideo::class, fn (GenerateVideo $job) => $job->replan === false);
    }

    public function test_save_only_keeps_the_video_as_it_is(): void
    {
        Queue::fake();
        $video = $this->video();
        $steps = $this->fields($video->plan['steps']);
        $steps[2]['say'] = 'Use the title people search for.';

        $this->put("/video-demo/studio/{$video->id}/plan", ['steps' => $steps, 'then' => 'save'])
            ->assertRedirect("/video-demo/studio/{$video->id}#vd-plan")
            ->assertSessionHas('status');

        $this->assertSame('Use the title people search for.', $video->fresh()->plan['steps'][2]['say']);
        $this->assertSame(VideoStatus::Ready, $video->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_plan_that_cant_be_recorded_is_refused_and_the_edits_are_kept_on_the_form(): void
    {
        $video = $this->video();
        $steps = $this->fields($video->plan['steps']);
        $steps[2]['target']['label'] = '';
        $steps[3]['title'] = 'Still here';

        $this->put("/video-demo/studio/{$video->id}/plan", ['steps' => $steps, 'then' => 'record'])
            ->assertRedirect("/video-demo/studio/{$video->id}#vd-plan")
            ->assertSessionHasErrors(['plan' => 'Step 3 (type) needs target.']);

        $this->assertSame('Publsh it', $video->fresh()->plan['steps'][3]['title']);
        $this->assertNull($video->fresh()->plan_edited_at);

        $this->get("/video-demo/studio/{$video->id}")->assertSee('Step 3 (type) needs target.')->assertSee('value="Still here"', false);
    }

    public function test_the_facade_edits_a_plan_and_refuses_one_that_cant_be_recorded(): void
    {
        $video = $this->video();

        VideoDemo::updatePlan($video, ['steps' => [['action' => 'goto', 'url' => '/jobs'], ['action' => 'pause', 'ms' => 1000, 'only_on' => ['mobile']]]]);
        $this->assertSame('Post a job', $video->fresh()->plan['title']);
        $this->assertSame(['mobile'], $video->fresh()->plan['steps'][1]['only_on']);
        $this->assertStringContainsString('Plan edited by hand: 2 steps', collect($video->fresh()->log)->last()['message']);

        try {
            VideoDemo::updatePlan($video, ['steps' => [['action' => 'dance'], ['action' => 'pause', 'only_on' => ['watch']]]]);
            $this->fail('An unknown action was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Step 1 (dance) needs a known action.', $e->getMessage());
            $this->assertStringContainsString('Step 2 runs only on watch', $e->getMessage());
        }
    }

    public function test_cleaning_a_step_keeps_only_its_fields(): void
    {
        $step = Plan::clean(['action' => ' click ', 'target' => ['role' => 'button', 'name' => ' Save ', 'evil' => 'x'], 'ms' => '99999', 'voice_ms' => 3, 'value' => '  two spaces', 'only_on' => ['mobile', 3]]);

        $this->assertSame('click', $step['action']);
        $this->assertSame(['role' => 'button', 'name' => 'Save', 'label' => null, 'placeholder' => null, 'text' => null, 'selector' => null, 'frame' => null], $step['target']);
        $this->assertSame(60_000, $step['ms']);
        $this->assertSame('  two spaces', $step['value']);
        $this->assertSame(['mobile'], $step['only_on']);
        $this->assertArrayNotHasKey('voice_ms', $step);
    }

    public function test_a_new_plan_starts_from_the_edited_one(): void
    {
        $this->startFakeAnthropic();
        try {
            $this->respond(1, [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'submit_plan', 'input' => ['title' => 'Post a job', 'summary' => 'x', 'steps' => [
                ['action' => 'goto', 'why' => 'x', 'url' => '/jobs', 'target' => null, 'value' => '', 'title' => '', 'text' => '', 'ms' => 0, 'chapter' => '', 'say' => '', 'only_on' => []],
            ]]]]);
            $video = $this->video(['plan_edited_at' => now(), 'status' => VideoStatus::Planning]);
            // A caption the last recording stretched from 3.5 to 4.9 seconds.
            $video->update(['plan' => [...$video->plan, 'steps' => [...$video->plan['steps'], ['action' => 'caption', 'title' => 'Done', 'ms' => 4900, 'fitted' => ['from' => 3500, 'to' => 4900]]]]]);

            app(Planner::class)->plan($video);

            $brief = $this->request(1)['body']['messages'][0]['content'];
            $this->assertStringContainsString('<edited_plan>', $brief);
            $this->assertStringContainsString('"title":"Publsh it"', $brief);
            $this->assertStringContainsString('"say":"Give it a clear title."', $brief);
            $this->assertStringNotContainsString('voice_ms', $brief);
            // With the length it was planned with.
            $this->assertStringContainsString('{"action":"caption","title":"Done","ms":3500}', $brief);
            $this->assertStringNotContainsString('fitted', $brief);
            $this->assertStringContainsString('The developer edited the video\'s last plan by hand.', $brief);

            // Plans that weren't edited aren't sent.
            $this->respond(2, [['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'submit_plan', 'input' => ['title' => 'x', 'summary' => 'x', 'steps' => [['action' => 'goto', 'why' => 'x', 'url' => '/jobs', 'target' => null, 'value' => '', 'title' => '', 'text' => '', 'ms' => 0, 'chapter' => '', 'say' => '', 'only_on' => []]]]]]);
            app(Planner::class)->plan($this->video(['status' => VideoStatus::Planning]));
            $this->assertStringNotContainsString('<edited_plan>', $this->request(2)['body']['messages'][0]['content']);
        } finally {
            $this->stopFakeAnthropic();
        }
    }
}
