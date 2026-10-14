<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;

/**
 * Records a real video of tests/fixtures/app. Needs Playwright and ffmpeg, so it
 * only runs when VIDEO_DEMO_TEST_NODE_MODULES (a node_modules folder with
 * Playwright) is set. VIDEO_DEMO_TEST_FFMPEG overrides the ffmpeg binary.
 */
class RecorderTest extends TestCase
{
    protected string $out = '';

    protected function setUp(): void
    {
        if (! getenv('VIDEO_DEMO_TEST_NODE_MODULES')) {
            $this->markTestSkipped('Set VIDEO_DEMO_TEST_NODE_MODULES to a node_modules folder with Playwright.');
        }

        parent::setUp();

        config([
            'video-demo.recorder.node_modules' => getenv('VIDEO_DEMO_TEST_NODE_MODULES'),
            'video-demo.recorder.ffmpeg' => getenv('VIDEO_DEMO_TEST_FFMPEG') ?: 'ffmpeg',
            'video-demo.recorder.base_url' => 'file://'.realpath(__DIR__.'/fixtures/app'),
        ]);
        $this->out = sys_get_temp_dir().'/video-demo-rec-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    protected function step(string $action, array $fields = []): array
    {
        $target = isset($fields['target'])
            ? [...['role' => null, 'name' => null, 'label' => null, 'placeholder' => null, 'text' => null, 'selector' => null], ...$fields['target']]
            : null;

        return [...['action' => $action, 'why' => '', 'url' => null, 'value' => null, 'title' => null, 'text' => null, 'ms' => null, 'chapter' => null], ...$fields, 'target' => $target];
    }

    public function test_records_and_encodes_a_plan(): void
    {
        $plan = new Plan('Post a job', 'Posting a job.', [
            $this->step('card', ['title' => 'Post a job', 'ms' => 1200]),
            $this->step('goto', ['url' => '/index.html', 'chapter' => 'Fill it in']),
            // Overlays must work on real pages, not only the blank page before the first goto
            $this->step('caption', ['title' => 'Start with the basics', 'ms' => 600]),
            $this->step('type', ['target' => ['label' => 'Job title'], 'value' => 'Laravel developer']),
            $this->step('select', ['target' => ['label' => 'Contract'], 'value' => 'Contract']),
            $this->step('click', ['target' => ['role' => 'button', 'name' => 'Publish job']]),
            $this->step('wait', ['target' => ['text' => 'Your job is live.']]),
        ]);

        $result = (new Recorder)->record($plan, $this->out);

        $this->assertTrue($result->ok, $result->summary());
        $this->assertFileExists($result->video);
        $this->assertSame('Fill it in', $result->chapters[0]['title']);

        $this->assertGreaterThan(0, $result->trimSeconds);
        (new Encoder)->encode($result->video, "$this->out/video.mp4", "$this->out/poster.jpg", $result->trimSeconds);
        $this->assertGreaterThan(10_000, filesize("$this->out/video.mp4"));
        $this->assertFileExists("$this->out/poster.jpg");
    }

    public function test_a_failed_step_reports_what_was_on_the_page(): void
    {
        $plan = new Plan('x', 'y', [
            $this->step('goto', ['url' => '/index.html']),
            $this->step('click', ['target' => ['role' => 'button', 'name' => 'Save draft']]),
        ]);

        $result = (new Recorder)->record($plan, $this->out);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->canRepair());
        $this->assertSame(2, $result->failedStep);
        $this->assertStringContainsString('Save draft', $result->error);
        $this->assertStringContainsString('button "Publish job"', $result->describeForRepair());
    }

    public function test_records_the_mobile_version_with_touch_and_its_own_steps(): void
    {
        $plan = new Plan('Post a job', 'Posting a job.', [
            $this->step('goto', ['url' => '/index.html']),
            // Only phones: the step would fail anywhere, so passing proves it ran
            $this->step('wait', ['target' => ['text' => 'Your job is live.'], 'only_on' => ['desktop']]),
            $this->step('type', ['target' => ['label' => 'Job title'], 'value' => 'Designer']),
            $this->step('click', ['target' => ['role' => 'button', 'name' => 'Publish job']]),
        ]);

        $result = (new Recorder)->record($plan, $this->out, ['theme' => 'dark', 'pace' => 'brisk'], 'mobile');

        $this->assertTrue($result->ok, $result->summary());
        $this->assertSame('mobile', $result->device);
        $job = json_decode(File::get("$this->out/job.json"), true);
        $this->assertSame(['width' => 390, 'height' => 844], $job['viewport']);
        $this->assertTrue($job['touch']);
        $this->assertSame('dark', $job['style']['theme']);

        (new Encoder)->encode($result->video, "$this->out/mobile.mp4", "$this->out/mobile.jpg", $result->trimSeconds);
        // Recorded at the phone's screen size, filling the frame
        [$width, $height] = getimagesize("$this->out/mobile.jpg");
        $this->assertSame([390, 844], [$width, $height]);
    }
}
