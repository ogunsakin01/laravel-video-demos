<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Registrar;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Mcp\Tools\GetVideo;
use Ogunsakin01\VideoDemo\Mcp\Tools\ListVideos;
use Ogunsakin01\VideoDemo\Mcp\Tools\MakeVideo;
use Ogunsakin01\VideoDemo\Mcp\Tools\PestTest;
use Ogunsakin01\VideoDemo\Mcp\Tools\PublishVideo;
use Ogunsakin01\VideoDemo\Mcp\Tools\VerifyVideos;
use Ogunsakin01\VideoDemo\Mcp\VideoDemosServer;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Verifier;

/**
 * The MCP server coding agents use to make, check and publish videos.
 */
class McpTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function video(array $overrides = []): Video
    {
        return Video::create([
            'title' => 'Post a job', 'prompt' => 'How a recruiter posts a job', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop'], 'version' => 1,
            'recordings' => ['desktop' => ['seconds' => 12.0, 'chapters' => []]],
            'plan' => ['title' => 'Post a job', 'summary' => '', 'steps' => [
                ['action' => 'goto', 'url' => '/jobs'],
                ['action' => 'click', 'target' => ['role' => 'link', 'name' => 'Post a job']],
            ]],
            ...$overrides,
        ]);
    }

    public function test_the_server_is_there_for_agents_where_the_studio_is(): void
    {
        $this->assertNotNull(app(Registrar::class)->getLocalServer('video-demos'));
    }

    public function test_agents_list_and_read_videos_and_get_their_pest_tests(): void
    {
        $video = $this->video();

        VideoDemosServer::tool(ListVideos::class)->assertOk()->assertSee(['"slug": "post-a-job"', '"status": "ready"']);
        VideoDemosServer::tool(GetVideo::class, ['video' => 'post-a-job'])->assertOk()->assertSee(route('video-demo.studio.show', $video));
        VideoDemosServer::tool(GetVideo::class, ['video' => 'nope'])->assertHasErrors(['There is no video [nope].']);
        VideoDemosServer::tool(PestTest::class, ['video' => 'post-a-job'])->assertOk()->assertSee(['tests/Browser/PostAJobTest.php', "visit('/jobs')"]);
    }

    public function test_agents_make_videos_within_the_limits_and_publish_them(): void
    {
        Queue::fake();
        Storage::fake('public');

        VideoDemosServer::tool(MakeVideo::class, ['description' => 'How a recruiter closes a job', 'length' => 30, 'style' => 'quick'])
            ->assertOk()->assertSee(['"status": "queued"', 'video-demo:work']);
        Queue::assertPushed(GenerateVideo::class);
        $this->assertSame([30, 0.5], [Video::latest('id')->first()->target_seconds, Video::latest('id')->first()->spendLimit()]);
        VideoDemosServer::tool(MakeVideo::class, ['description' => 'x', 'style' => 'nonsense'])->assertHasErrors();

        $video = $this->video();
        Storage::disk('local')->put($video->workFile('desktop'), 'mp4');
        Storage::disk('local')->put($video->workFile('desktop', 'poster'), 'jpg');
        VideoDemosServer::tool(PublishVideo::class, ['video' => 'post-a-job'])->assertOk()->assertSee('Published post-a-job');
        $this->assertTrue($video->fresh()->isPublished());
        VideoDemosServer::tool(PublishVideo::class, ['video' => 'post-a-job', 'unpublish' => true])->assertOk();
        $this->assertFalse($video->fresh()->isPublished());
    }

    public function test_agents_check_which_published_videos_a_change_broke(): void
    {
        $this->video(['published_at' => now()]);
        $this->video(['slug' => 'draft', 'title' => 'Draft']);
        $this->app->instance(Verifier::class, new class extends Verifier
        {
            public function __construct() {}

            public function verify(Video $video): array
            {
                return ['status' => 'done', 'ok' => false, 'checked' => ['desktop'], 'device' => 'desktop', 'step' => 2, 'action' => 'click', 'error' => 'Could not find a visible element with role "link", name "Post a job".', 'url' => '/jobs', 'issues' => [], 'screenshot' => false];
            }
        });

        // Only published videos, with what broke.
        VideoDemosServer::tool(VerifyVideos::class)->assertOk()->assertSee(['"ok": false', '"slug": "post-a-job"', '"step": 2'])->assertDontSee('"slug": "draft"');
    }
}
