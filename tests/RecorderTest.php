<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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
            ? [...['role' => null, 'name' => null, 'label' => null, 'placeholder' => null, 'text' => null, 'selector' => null, 'frame' => null], ...$fields['target']]
            : null;

        return [...['action' => $action, 'why' => '', 'url' => null, 'value' => null, 'title' => null, 'text' => null, 'ms' => null, 'chapter' => null], ...$fields, 'target' => $target];
    }

    public function test_records_and_encodes_a_plan(): void
    {
        $plan = new Plan('Post a job', 'Posting a job.', [
            $this->step('card', ['title' => 'Post a job', 'ms' => 1200]),
            $this->step('goto', ['url' => '/index.html', 'chapter' => 'Fill it in']),
            // Overlays must work on real pages, not only the blank page before the first goto.
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
        (new Encoder)->encode($result->video, "$this->out/video.mp4", "$this->out/poster.jpg", $result->trimSeconds, Recorder::speed());
        $this->assertGreaterThan(10_000, filesize("$this->out/video.mp4"));

        // Played back at 2x: the file is as long as the result says, about
        // half the time the recording took.
        $this->assertEqualsWithDelta($result->seconds, $this->duration("$this->out/video.mp4"), 0.6);
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
            // Only phones: the step would fail anywhere, so passing proves it ran.
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

        (new Encoder)->encode($result->video, "$this->out/mobile.mp4", "$this->out/mobile.jpg", $result->trimSeconds, Recorder::speed());
        // Recorded at the phone's screen size, filling the frame.
        [$width, $height] = getimagesize("$this->out/mobile.jpg");
        $this->assertSame([390, 844], [$width, $height]);
    }

    public function test_fills_card_fields_inside_an_iframe_like_embedded_payment_forms(): void
    {
        $frame = 'iframe[title="Secure card payment input frame"]';
        $plan = new Plan('Pay', 'Paying by card.', [
            $this->step('goto', ['url' => '/checkout.html']),
            $this->step('type', ['target' => ['label' => 'Card number', 'frame' => $frame], 'value' => '4242 4242 4242 4242']),
            $this->step('click', ['target' => ['role' => 'button', 'name' => 'Pay']]),
            $this->step('wait', ['target' => ['text' => 'Payment received']]),
        ]);

        $result = (new Recorder)->record($plan, $this->out);
        $this->assertTrue($result->ok, $result->summary());

        // When a field in a frame can't be found, the AI model is shown the frame and what's in it.
        $plan = new Plan('Pay', 'Paying by card.', [
            $this->step('goto', ['url' => '/checkout.html']),
            $this->step('type', ['target' => ['label' => 'Card number'], 'value' => '4242 4242 4242 4242']),
        ]);
        $result = (new Recorder)->record($plan, $this->out);

        $this->assertSame(2, $result->failedStep);
        $this->assertStringContainsString('iframe (target.frame: '.$frame.')', $result->snapshot);
        $this->assertStringContainsString('textbox "Card number"', $result->snapshot);
    }

    public function test_checks_pages_before_any_ai_is_spent(): void
    {
        $port = $this->freePort();
        $server = Process::path(__DIR__.'/fixtures')->start(['php', '-S', "127.0.0.1:$port", 'check-router.php']);

        try {
            $this->waitForServer($port);
            config(['video-demo.recorder.base_url' => "http://127.0.0.1:$port"]);

            $problems = collect((new Recorder)->checkPages(['/', '/reports', '/settings', '/chart', '/missing', 'not-a-path'], 'guest'))->keyBy('path');

            $this->assertSame(['/reports', '/settings', '/chart', '/missing'], $problems->keys()->all());
            $this->assertSame('The page returned HTTP 500', $problems['/reports']['problem']);
            $this->assertSame('Undefined variable $total', $problems['/reports']['detail']);
            $this->assertStringContainsString('recorded as a guest', $problems['/settings']['problem']);
            $this->assertSame('The page threw a JavaScript error', $problems['/chart']['problem']);
            $this->assertStringContainsString("reading 'render'", $problems['/chart']['detail']);
            $this->assertSame(404, $problems['/missing']['status']);

            // The start-page list keeps only pages that answer with HTML, titled by their heading.
            $scan = (new Recorder)->scanPages(['/', '/reports', '/settings', '/chart', '/missing', '/feed.json'], 'guest');
            $this->assertSame([['path' => '/', 'title' => 'Welcome'], ['path' => '/chart', 'title' => 'Chart']], collect($scan['pages'])->sortBy('path')->values()->all());
            $this->assertSame(['/feed.json' => 'not a page (application/json)', '/missing' => 'HTTP 404', '/reports' => 'HTTP 500', '/settings' => 'redirects'],
                collect($scan['skipped'])->sortBy('path')->pluck('reason', 'path')->all());

            // During recording, an error from the app stops the job instead of asking the AI model to repair the plan.
            $result = (new Recorder)->record(new Plan('x', 'x', [
                $this->step('goto', ['url' => '/server-recording']),
                $this->step('click', ['target' => ['role' => 'button', 'name' => 'Save']]),
            ]), $this->out, [], 'desktop', 'guest');
            $this->assertFalse($result->ok);
            $this->assertFalse($result->canRepair());
            $this->assertSame('/server-recording', $result->issue()['path']);
        } finally {
            $server->stop();
        }
    }

    protected function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    protected function waitForServer(int $port): void
    {
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                return;
            }
            usleep(100_000);
        }
        $this->fail('The fixture server did not start.');
    }

    protected function duration(string $file): float
    {
        $out = Process::run([config('video-demo.recorder.ffmpeg'), '-i', $file])->errorOutput();
        preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $out, $m);

        return $m ? $m[1] * 3600 + $m[2] * 60 + (float) $m[3] : 0.0;
    }
}
