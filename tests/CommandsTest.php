<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;

/**
 * Tests the commands AI agents and scripts use to make videos without the studio.
 */
class CommandsTest extends TestCase
{
    use FakesAnthropic;

    protected function runJson(string $command, array $parameters = []): array
    {
        Artisan::call($command, [...$parameters, '--json' => true]);

        return json_decode(Artisan::output(), true);
    }

    public function test_generate_can_queue_a_video_with_every_option(): void
    {
        Queue::fake();

        $result = $this->runJson('video-demo:generate', [
            'description' => 'How a recruiter posts a job',
            '--title' => 'Post a job',
            '--length' => 45,
            '--style' => 'Promo ad',
            '--devices' => 'mobile,desktop',
            '--start' => '/jobs',
            '--private' => true,
            '--max-cost' => '0.40',
            '--max-steps' => 20,
            '--as' => 'guest',
            '--queue' => true,
        ]);

        $this->assertSame('queued', $result['status']);
        $video = Video::findOrFail($result['id']);
        $this->assertSame('ad', $video->style['key']);
        $this->assertSame(['desktop', 'mobile'], $video->devices);
        $this->assertSame([45, '/jobs', 'private'], [$video->target_seconds, $video->start_path, $video->visibility]);
        $this->assertSame([0.4, 20, 'guest', 'Guest (signed out)'], [$video->spendLimit(), $video->max_steps, $video->record_as, $video->record_as_label]);
        // Without --model, the cheapest reliable model is used.
        $this->assertSame('claude-sonnet-5-5', $video->model);
        $this->assertStringContainsString('/video-demo/studio/'.$video->id, $result['studio_url']);
        Queue::assertPushed(GenerateVideo::class);
    }

    public function test_generate_explains_bad_options(): void
    {
        $this->assertSame('Unknown style [cinema]. Choose one of: clean, quick, detailed, showcase, ad.', $this->runJson('video-demo:generate', ['description' => 'x', '--style' => 'cinema'])['error']);
        $this->assertStringContainsString('Unknown versions: watch', $this->runJson('video-demo:generate', ['description' => 'x', '--devices' => 'desktop,watch'])['error']);
        $this->assertStringContainsString('between 10 and 300', $this->runJson('video-demo:generate', ['description' => 'x', '--length' => 2])['error']);
        $this->assertStringContainsString('Unknown model [gpt-nope]', $this->runJson('video-demo:generate', ['description' => 'x', '--model' => 'gpt-nope'])['error']);
        $this->assertStringContainsString('spending limit must be a number', $this->runJson('video-demo:generate', ['description' => 'x', '--max-cost' => 'lots'])['error']);
        $this->assertStringContainsString('at least 3', $this->runJson('video-demo:generate', ['description' => 'x', '--max-steps' => 1])['error']);
        $this->assertSame(0, Video::count());

        $this->artisan('video-demo:generate', ['description' => 'x', '--start' => 'jobs'])->assertFailed();
    }

    public function test_generate_waits_for_the_video_and_can_publish_it(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->startFakeAnthropic();

        // Claude submits a plan straight away.
        $this->respond(1, [['type' => 'tool_use', 'id' => 'tu_plan', 'name' => 'submit_plan', 'input' => [
            'title' => 'Post a job', 'summary' => 'Posting a job.', 'steps' => [[
                'action' => 'goto', 'why' => 'x', 'url' => '/jobs', 'target' => null, 'value' => null,
                'title' => null, 'text' => null, 'ms' => null, 'chapter' => 'Start', 'only_on' => null,
            ]],
        ]]]);

        // Stand-ins for the browser and ffmpeg, so this runs anywhere.
        $this->app->instance(Recorder::class, new class extends Recorder
        {
            public function record(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?\Closure $shouldStop = null): RecordingResult
            {
                File::ensureDirectoryExists($dir);
                File::put("$dir/raw.webm", 'webm');

                return RecordingResult::fromArray(['ok' => true, 'video' => "$dir/raw.webm", 'durationMs' => 12_000, 'chapters' => [['title' => 'Start', 'at' => 0.5]]], $device);
            }
        });
        $this->app->instance(Encoder::class, new class extends Encoder
        {
            public function encode(string $webm, string $mp4, string $poster, float $trimSeconds = 0, float $speed = 1): void
            {
                File::put($mp4, 'mp4');
                File::put($poster, 'jpg');
            }
        });

        $result = $this->runJson('video-demo:generate', ['description' => 'How a recruiter posts a job', '--devices' => 'desktop,tablet', '--publish' => true]);
        $this->stopFakeAnthropic();

        $this->assertSame('ready', $result['status'], (string) $result['error']);
        $this->assertSame('Post a job', $result['title']);
        $this->assertSame(['desktop', 'tablet'], $result['versions']);
        $this->assertSame(12.0, (float) $result['seconds']);
        $this->assertGreaterThan(0, $result['cost']);
        $this->assertTrue($result['published']);
        $this->assertStringContainsString('/help/videos/how-a-recruiter-posts-a-job', $result['library_url']);
        $this->assertSame(['desktop', 'tablet'], array_keys(app(Manifest::class)->find('how-a-recruiter-posts-a-job', 'public')['sources']));
    }

    public function test_list_and_publish(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $video = Video::create([
            'title' => 'Invite a teammate', 'prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-opus-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop'], 'recordings' => ['desktop' => ['seconds' => 20.0, 'chapters' => []]], 'version' => 1,
        ]);
        Storage::disk('local')->put($video->workFile('desktop'), 'mp4');
        Storage::disk('local')->put($video->workFile('desktop', 'poster'), 'jpg');

        $this->assertSame('invite-a-teammate', $this->runJson('video-demo:list')[0]['slug']);

        $this->artisan('video-demo:publish', ['video' => 'invite-a-teammate'])->assertSuccessful();
        $this->assertTrue($video->fresh()->isPublished());

        $this->artisan('video-demo:publish', ['video' => (string) $video->id, '--unpublish' => true])->assertSuccessful();
        $this->assertFalse($video->fresh()->isPublished());

        $this->artisan('video-demo:publish', ['video' => 'missing'])->assertFailed();
    }
}
