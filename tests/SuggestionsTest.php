<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Artisan;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;

/** "Ideas": the AI suggests which videos to make first */
class SuggestionsTest extends TestCase
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

    protected function suggestions(int $n, array $ideas): void
    {
        $this->respond($n, [['type' => 'text', 'text' => json_encode(['suggestions' => $ideas])]], 'end_turn',
            ['input_tokens' => 3_000, 'output_tokens' => 600, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0]);
    }

    public function test_ideas_are_suggested_kept_and_open_in_the_form(): void
    {
        Video::create(['title' => 'Post a job', 'prompt' => 'x', 'target_seconds' => 60, 'model' => 'claude-opus-5-5', 'effort' => 'high', 'status' => 'ready']);
        $this->suggestions(1, [
            ['title' => 'Invite a teammate', 'prompt' => 'How an admin invites a colleague.', 'why' => 'Teams grow.', 'start_path' => '/team', 'target_seconds' => 45, 'style' => 'quick'],
            ['title' => 'Show off hiring', 'prompt' => 'A short ad about hiring.', 'why' => 'For the website.', 'start_path' => '', 'target_seconds' => 999, 'style' => 'ad'],
        ]);

        $this->get('/video-demo/studio/ideas')->assertOk()->assertSee('No ideas yet');
        $this->post('/video-demo/studio/ideas', ['model' => 'claude-opus-5-5'])->assertRedirect('/video-demo/studio/ideas');

        // The routes, the styles and the videos already made went to the model
        $prompt = $this->request(1)['body']['messages'][0]['content'];
        $this->assertStringContainsString('<videos_already_made>', $prompt);
        $this->assertStringContainsString('- Post a job', $prompt);
        $this->assertStringContainsString('- ad: Promo ad.', $prompt);

        $page = $this->get('/video-demo/studio/ideas')->assertOk()->assertSee('Invite a teammate')->assertSee('Teams grow.');
        // "Make this video" fills in the form; lengths are kept within the limit
        $page->assertSee('/video-demo/studio/create?prompt=How%20an%20admin%20invites%20a%20colleague.&amp;title=Invite%20a%20teammate&amp;start_path=%2Fteam&amp;target_seconds=45&amp;style=quick', false);
        $page->assertSee('target_seconds=300&amp;style=ad', false);

        $this->get('/video-demo/studio/create?prompt=How+an+admin+invites+a+colleague.&title=Invite+a+teammate&start_path=%2Fteam&target_seconds=45&style=quick')
            ->assertSee('value="Invite a teammate"', false)
            ->assertSee('value="/team"', false)
            ->assertSee('How an admin invites a colleague.</textarea>', false);
    }

    public function test_suggest_command_prints_json_for_agents(): void
    {
        $this->suggestions(1, [['title' => 'Invite a teammate', 'prompt' => 'How an admin invites a colleague.', 'why' => 'Teams grow.', 'start_path' => '/team', 'target_seconds' => 45, 'style' => 'quick']]);

        Artisan::call('video-demo:suggest', ['--json' => true]);
        $result = json_decode(Artisan::output(), true);

        $this->assertSame('Invite a teammate', $result['suggestions'][0]['title']);
        $this->assertTrue($result['cost_known']);
        $this->assertGreaterThan(0, $result['cost']);
    }
}
