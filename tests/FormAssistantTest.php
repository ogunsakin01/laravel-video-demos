<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;

/**
 * Tests "Complete with AI", which fills in the new video form from a rough note.
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
}
