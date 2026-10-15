<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;

/**
 * Tests "Complete with AI", which fills in the new video form from a rough note,
 * and "Improve with AI", which rewrites a video's description before it is made again.
 */
class FormAssistantTest extends TestCase
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

    public function test_completes_the_form_from_a_rough_note(): void
    {
        $this->respond(1, [['type' => 'text', 'text' => json_encode([
            'title' => 'Approve a holiday request',
            'prompt' => 'How a manager approves a holiday request, from the inbox to the confirmation.',
            'start_path' => '/inbox',
            'target_seconds' => 900,
        ])]], 'end_turn', ['input_tokens' => 2_000, 'output_tokens' => 300, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0], 'claude-sonnet-5-5');

        $this->postJson('/video-demo/studio/complete', ['prompt' => 'approve holiday', 'model' => 'claude-sonnet-5-5'])
            ->assertOk()
            ->assertJson([
                'title' => 'Approve a holiday request',
                'start_path' => '/inbox',
                // Kept within the length limit.
                'target_seconds' => 300,
                // 2,000 input and 300 output tokens on Sonnet 5.5.
                'cost' => 0.007,
            ]);

        $body = $this->request(1)['body'];
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertStringContainsString('approve holiday', $body['messages'][0]['content']);
    }

    public function test_explains_when_claude_cannot_be_reached(): void
    {
        // No scripted reply, so the fake API answers with an error.
        $this->postJson('/video-demo/studio/complete', ['prompt' => '', 'model' => 'claude-opus-5-5'])
            ->assertStatus(502)
            ->assertJsonPath('error', 'The AI provider could not be reached. Check your API key and try again.');
    }

    public function test_improves_a_description_from_how_the_video_went(): void
    {
        $video = Video::create([
            'prompt' => 'A recruiter rates a talent', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Failed, 'start_path' => '/dashboard/talents', 'duration_seconds' => 48.2, 'cost' => 0.30,
            'style' => ['name' => 'Spotlight'],
            'plan' => ['title' => 'x', 'summary' => '', 'steps' => [
                ['action' => 'card', 'title' => 'Rate a talent', 'text' => 'In under a minute'],
                ['action' => 'goto', 'url' => '/dashboard/talents'],
                ['action' => 'click', 'target' => ['role' => 'link', 'name' => 'Sofia Mendes']],
            ]],
            'log' => [
                ['at' => now()->toIso8601String(), 'message' => 'Read app/Http/Controllers/TalentController.php'],
                ['at' => now()->toIso8601String(), 'message' => 'Step 3 failed (Desktop): Could not find a visible element with role "link", name "Sofia Mendes".'],
            ],
            'error' => 'Recording failed with key sk-ant-api03-'.str_repeat('a', 40),
            'issues' => [['path' => '/dashboard/talents', 'problem' => 'The page returned HTTP 403', 'status' => 403, 'url' => null, 'detail' => 'Add your photo']],
        ]);
        $this->respond(1, [['type' => 'text', 'text' => json_encode([
            'prompt' => 'A recruiter opens Talents, picks the first profile and leaves a four-star rating. It ends on the saved rating.',
            'start_path' => '',
            'target_seconds' => 5,
            'changes' => ['Picks the first profile instead of a name that was not on the page.', 'Ends on the saved rating, as you asked.'],
        ])]], 'end_turn', ['input_tokens' => 2_000, 'output_tokens' => 300, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0], 'claude-sonnet-5-5');

        $this->postJson("/video-demo/studio/{$video->id}/improve", ['wish' => 'end on the saved rating', 'model' => 'claude-sonnet-5-5'])
            ->assertOk()
            ->assertJson([
                'prompt' => 'A recruiter opens Talents, picks the first profile and leaves a four-star rating. It ends on the saved rating.',
                // An empty start page keeps the video's own; the length is kept within limits.
                'start_path' => '/dashboard/talents',
                'target_seconds' => 10,
                'changes' => ['Picks the first profile instead of a name that was not on the page.', 'Ends on the saved rating, as you asked.'],
                'cost' => 0.007,
                'video_cost' => 0.307,
            ]);

        // It is told what the developer wants, the last plan, and what went wrong; never secrets.
        $sent = $this->request(1)['body']['messages'][0]['content'];
        $this->assertStringContainsString("<developer_wants>\nend on the saved rating", $sent);
        $this->assertStringContainsString('3. click role: link, name: Sofia Mendes', $sent);
        $this->assertStringContainsString('1. card "Rate a talent" — In under a minute', $sent);
        $this->assertStringContainsString('Step 3 failed (Desktop)', $sent);
        $this->assertStringContainsString('/dashboard/talents: The page returned HTTP 403 (Add your photo)', $sent);
        $this->assertStringContainsString('the last video came out at 48 seconds', $sent);
        $this->assertStringNotContainsString('sk-ant-api03', $sent);
        $this->assertStringNotContainsString('Read app/Http', $sent);

        // It only fills in the form: nothing is started, and what it cost is added to the video.
        $this->assertSame(VideoStatus::Failed, $video->fresh()->status);
        $this->assertEqualsWithDelta(0.307, $video->fresh()->cost, 0.0001);

        $video->update(['status' => VideoStatus::Planning]);
        $this->postJson("/video-demo/studio/{$video->id}/improve", ['model' => 'claude-sonnet-5-5'])->assertStatus(409);
    }

    public function test_the_video_page_offers_improve_with_ai(): void
    {
        $video = Video::create(['prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'status' => VideoStatus::Ready]);

        $this->get("/video-demo/studio/{$video->id}")
            ->assertSee('Improve with AI')
            ->assertSee('What should be better?')
            ->assertSee(json_encode(route('video-demo.studio.improve', $video)), false);
    }
}
