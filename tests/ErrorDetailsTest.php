<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\RecordingFailed;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Ogunsakin01\VideoDemo\Studio\ErrorReport;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests the full error view: what is kept when a video fails, and the modal
 * that shows it on the video's page.
 */
class ErrorDetailsTest extends TestCase
{
    protected function failingRecorder(): Recorder
    {
        return new class extends Recorder
        {
            public function checkPages(array $paths, ?string $recordAs = null, string $device = 'desktop', ?Closure $shouldStop = null): array
            {
                return [];
            }

            public function record(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null, ?float $speed = null, array $voice = []): RecordingResult
            {
                File::ensureDirectoryExists($dir);
                File::put("$dir/failure.jpg", 'jpeg');

                return RecordingResult::fromArray([
                    'ok' => false, 'failedStep' => 2, 'url' => 'http://localhost/settings', 'title' => 'Visibility and privacy',
                    'error' => 'Waited 15 seconds for role "button", name "Show on my profile" to appear.',
                    'errorFull' => "Waited 15 seconds for role \"button\", name \"Show on my profile\" to appear.\nCall log:\n  - waiting for getByRole('button')",
                    'snapshot' => '- button "Hide from my profile"',
                    'screenshot' => "$dir/failure.jpg",
                    'browserLog' => [['kind' => 'response', 'text' => 'POST http://localhost/github/toggle', 'status' => 419, 't' => 3200]],
                ], $device);
            }
        };
    }

    public function test_a_failed_recording_keeps_the_full_error_and_the_page_shows_it(): void
    {
        Storage::fake('local');
        $video = Video::create([
            'title' => 'Connect GitHub', 'prompt' => 'Show and hide GitHub badges', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5',
            'effort' => 'medium', 'status' => VideoStatus::Queued, 'sample_data' => false, 'devices' => ['desktop'],
            'plan' => ['title' => 'x', 'summary' => '', 'steps' => [
                ['action' => 'goto', 'why' => 'Open', 'url' => '/settings'],
                ['action' => 'click', 'why' => 'Show again', 'target' => ['role' => 'button', 'name' => 'Show on my profile']],
            ]],
        ]);
        $this->app->instance(Recorder::class, $this->failingRecorder());

        $job = new GenerateVideo($video, replan: false);
        try {
            $this->app->call([$job, 'handle']);
            $this->fail('The job should fail.');
        } catch (RecordingFailed $e) {
            $job->failed($e);
        }

        $video->refresh();
        $this->assertSame(VideoStatus::Failed, $video->status);
        $report = $video->error_details;
        $this->assertSame(RecordingFailed::class, $report['exception']['class']);
        $this->assertSame('src/Jobs/GenerateVideo.php', str_replace('\\', '/', substr($report['exception']['file'], strpos($report['exception']['file'], 'src/'))));
        // The frame it was thrown in comes with the code around the line.
        $first = $report['exception']['frames'][0];
        $this->assertNotEmpty($first['code']['lines']);
        $this->assertStringContainsString('RecordingFailed', implode("\n", $first['code']['lines']));
        // What the recorder saw, with the step as that version played it.
        $this->assertSame(2, $report['step']['number']);
        $this->assertSame('Desktop', $report['step']['device']);
        $this->assertSame('Show on my profile', $report['step']['plan']['target']['name']);
        $this->assertStringContainsString('Call log:', $report['step']['error']);
        $this->assertTrue($report['step']['screenshot']);
        $this->assertSame(419, $report['step']['browser'][0]['status']);
        $this->assertSame('Connect GitHub', $report['context']['Video']['Title']);

        $this->get("/video-demo/studio/{$video->id}")
            ->assertSee('View full error')
            ->assertSee('id="vd-error-details"', false)
            ->assertSee('Where the recording stopped')
            ->assertSee('Stack trace')
            ->assertSee('POST http://localhost/github/toggle')
            ->assertSee('Copy as Markdown');
        $this->get("/video-demo/studio/{$video->id}/error.jpg")->assertOk();

        $markdown = ErrorReport::markdown($report);
        $this->assertStringStartsWith('# '.RecordingFailed::class, $markdown);
        $this->assertStringContainsString('Step 2 (Desktop) on http://localhost/settings', $markdown);

        // Trying again clears it, with the error.
        Queue::fake();
        $this->post("/video-demo/studio/{$video->id}/retry", ['mode' => 'record'])->assertRedirect();
        $this->assertNull($video->fresh()->error_details);
        $this->get("/video-demo/studio/{$video->id}/error.jpg")->assertNotFound();
    }

    public function test_a_failure_without_a_kept_report_still_gets_one(): void
    {
        $video = Video::create(['prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'status' => VideoStatus::Recording]);

        (new GenerateVideo($video))->failed(new RuntimeException('The job ran too long.', 0, new RuntimeException('Inner cause')));

        $report = $video->fresh()->error_details;
        $this->assertSame('The job ran too long.', $report['exception']['message']);
        $this->assertSame('Inner cause', $report['exception']['previous'][0]['message']);
        $this->assertNull($report['step']);
        $this->get("/video-demo/studio/{$video->id}")->assertSee('View full error')->assertDontSee('class="vd-error-grid"', false);
    }

    public function test_errors_the_app_throws_while_recorded_on_sample_data_are_kept(): void
    {
        $path = Sandbox::dir().'/1-test.sqlite';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');
        $sandbox = new Sandbox($path);

        $keep = new ReflectionMethod(Sandbox::class, 'keepErrors');
        $keep->invoke(null, $this->app, $path);
        Log::error('Something broke', ['exception' => new RuntimeException('No GitHub profile for this user')]);
        Log::info('Not an exception');

        $errors = $sandbox->appErrors();
        $this->assertCount(1, $errors);
        $this->assertSame('No GitHub profile for this user', $errors[0]['message']);
        $this->assertSame('GET', $errors[0]['method']);
        $this->assertNotEmpty($errors[0]['frames']);

        $sandbox->delete();
        $this->assertFileDoesNotExist($path.'.errors.json');
    }
}
