<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Artisan;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoIdea;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;

/**
 * Tests "Ideas", where the AI model suggests which videos to make first.
 */
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

        // The routes, the styles and the videos already made went to the model.
        $prompt = $this->request(1)['body']['messages'][0]['content'];
        $this->assertStringContainsString('<videos_already_made>', $prompt);
        $this->assertStringContainsString('- Post a job', $prompt);
        $this->assertStringContainsString('- ad: Promo ad.', $prompt);

        $page = $this->get('/video-demo/studio/ideas')->assertOk()->assertSee('Invite a teammate')->assertSee('Teams grow.');
        // "Make this video" fills in the form; lengths are kept within the limit.
        $page->assertSee('/video-demo/studio/create?prompt=How%20an%20admin%20invites%20a%20colleague.&amp;title=Invite%20a%20teammate&amp;start_path=%2Fteam&amp;target_seconds=45&amp;style=quick', false);
        $page->assertSee('target_seconds=300&amp;style=ad', false);

        $this->get('/video-demo/studio/create?prompt=How+an+admin+invites+a+colleague.&title=Invite+a+teammate&start_path=%2Fteam&target_seconds=45&style=quick')
            ->assertSee('value="Invite a teammate"', false)
            ->assertSee('value="/team"', false)
            ->assertSee('How an admin invites a colleague.</textarea>', false);
    }

    public function test_each_idea_is_for_the_type_of_user_who_does_it(): void
    {
        config(['video-demo.recorder.user_types' => ['Admin' => 'admin@example.com', 'Member' => 'member@example.com']]);
        $this->suggestions(1, [
            ['title' => 'Invite a teammate', 'prompt' => 'How an admin invites a colleague.', 'why' => 'Teams grow.', 'start_path' => '/team', 'target_seconds' => 45, 'style' => 'quick', 'record_as' => 'type:admin'],
            ['title' => 'Join the waitlist', 'prompt' => 'How a visitor joins.', 'why' => 'First step.', 'start_path' => '/join', 'target_seconds' => 30, 'style' => 'quick', 'record_as' => 'guest'],
            ['title' => 'Edit your profile', 'prompt' => 'How a member edits it.', 'why' => 'Often asked.', 'start_path' => '', 'target_seconds' => 30, 'style' => 'quick', 'record_as' => 'type:owner'],
        ]);

        $this->post('/video-demo/studio/ideas')->assertRedirect();

        // The model is told the user types, and sees every route's middleware to tell who can open what.
        $body = $this->request(1)['body'];
        $this->assertStringContainsString("<user_types>\n- guest: Guest (signed out)\n- type:admin: Admin\n- type:member: Member\n</user_types>", $body['messages'][0]['content']);
        $this->assertStringContainsString('<routes>', $body['messages'][0]['content']);
        $this->assertSame(['guest', 'type:admin', 'type:member'], $body['output_config']['format']['schema']['properties']['suggestions']['items']['properties']['record_as']['enum']);

        // Only types the app has are kept.
        $this->assertSame(['type:admin', 'guest', null], VideoIdea::orderBy('id')->pluck('record_as')->all());

        $this->get('/video-demo/studio/ideas')
            ->assertSee('For Admin')
            ->assertSee('record_as=type%3Aadmin', false);
        $this->get('/video-demo/studio/create?prompt=x&record_as=type%3Aadmin')->assertSee('<option value="type:admin" selected', false);
    }

    public function test_suggest_command_prints_json_for_agents(): void
    {
        $this->suggestions(1, [['title' => 'Invite a teammate', 'prompt' => 'How an admin invites a colleague.', 'why' => 'Teams grow.', 'start_path' => '/team', 'target_seconds' => 45, 'style' => 'quick']]);

        Artisan::call('video-demo:suggest', ['--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        $this->assertArrayHasKey('suggestions', $result, $output);
        $this->assertSame('Invite a teammate', $result['suggestions'][0]['title']);
        $this->assertTrue($result['cost_known']);
        $this->assertGreaterThan(0, $result['cost']);
    }

    public function test_ideas_are_saved_added_to_and_replaced(): void
    {
        $invite = ['title' => 'Invite a teammate', 'prompt' => 'How an admin invites a colleague.', 'why' => 'Teams grow.', 'start_path' => '/team', 'target_seconds' => 45, 'style' => 'quick'];
        $payroll = ['title' => 'Run payroll', 'prompt' => 'How to run payroll.', 'why' => 'Monthly.', 'start_path' => '', 'target_seconds' => 60, 'style' => 'quick'];
        $this->suggestions(1, [$invite]);
        $this->suggestions(2, [['title' => 'invite a teammate ', ...array_diff_key($invite, ['title' => 1])], $payroll]);
        $this->suggestions(3, [$payroll]);

        $this->post('/video-demo/studio/ideas');
        $this->assertSame(['Invite a teammate'], VideoIdea::pluck('title')->all());
        $this->assertGreaterThan(0, VideoIdea::first()->cost);

        // "Suggest more" tells the AI model what is already there and drops repeats.
        $this->post('/video-demo/studio/ideas', ['replace' => '0']);
        $this->assertStringContainsString("<ideas_already_suggested>\n- Invite a teammate", $this->request(2)['body']['messages'][0]['content']);
        $this->assertSame(['Invite a teammate', 'Run payroll'], VideoIdea::orderBy('id')->pluck('title')->all());

        // Ideas made into videos survive "Replace all"; the rest go.
        $video = Video::create(['prompt' => 'x', 'target_seconds' => 60, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'status' => 'ready']);
        VideoIdea::firstWhere('title', 'Invite a teammate')->update(['video_id' => $video->id]);
        $this->post('/video-demo/studio/ideas', ['replace' => '1']);
        $this->assertSame(['Invite a teammate', 'Run payroll'], VideoIdea::orderBy('id')->pluck('title')->all());
        $this->assertSame(1, VideoIdea::whereNull('video_id')->count());

        $this->get('/video-demo/studio/ideas')->assertSee('Made into a video')->assertSee('Open the video')->assertSee('Suggest more')->assertSee('Replace all');

        $this->delete('/video-demo/studio/ideas/'.VideoIdea::firstWhere('title', 'Run payroll')->id)->assertRedirect();
        $this->assertSame(1, VideoIdea::count());
    }
}
