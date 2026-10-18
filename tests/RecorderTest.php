<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\ComponentAttributeBag;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Camera;
use Ogunsakin01\VideoDemo\Recording\Encoder;
use Ogunsakin01\VideoDemo\Recording\Exporter;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;

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

    public function test_finds_sections_and_shows_the_video_in_one_that_fits(): void
    {
        $recorder = new Recorder;
        // What the AI sees when it looks at a page of an ordinary, server-rendered app.
        $page = $recorder->lookAtPage('/index.html');
        $this->assertTrue($page['ok'], (string) $page['error']);
        $this->assertStringContainsString('heading "Post a job"', $page['snapshot']);

        $map = $recorder->mapPages(['/index.html', '/spots.html']);

        $sections = collect($map['pages'][0]['sections'])->keyBy('kind');
        $this->assertSame('Post a job', $map['pages'][0]['title']);
        $this->assertSame(['header', 'main', 'form'], $sections->keys()->all());
        $this->assertSame('Post a job', $sections['main']['heading']);
        $this->assertSame('form', $sections['form']['tag']);
        $spots = collect($map['pages'][1]['sections'])->keyBy('heading');

        $html = view('video-demo::partials.collapsible-player', [
            'slug' => 'post-a-job', 'title' => 'Post a <job>', 'seconds' => 42, 'open' => false,
            'attributes' => new ComponentAttributeBag, 'video' => ['title' => 'Post a job'],
            'sources' => ['desktop' => $source = ['video' => '', 'poster' => '', 'subtitles' => null, 'width' => 1280, 'height' => 800]], 'first' => $source,
        ])->render();
        $checked = $recorder->shootSpots([
            ['path' => '/index.html', 'selector' => $sections['form']['selector'], 'positions' => ['before', 'top']],
            // Beside the card in a flex row, it would squeeze the row; inside the card it fits.
            ['path' => '/spots.html', 'selector' => $spots['Listings']['selector'], 'positions' => ['before', 'top']],
            // A 150px box can't hold it anywhere.
            ['path' => '/spots.html', 'selector' => '.tiny', 'positions' => ['top']],
            ['path' => '/index.html', 'selector' => '#nowhere', 'positions' => ['top']],
        ], $html, $this->out.'/spots', ['desktop', 'mobile']);

        $this->assertSame([true, 'before', true], [$checked[0]['found'], $checked[0]['position'], $checked[0]['ok']], json_encode($checked[0]['problems']));
        $this->assertSame(['top', true], [$checked[1]['position'], $checked[1]['ok']], json_encode($checked[1]['problems']));
        $this->assertFalse($checked[2]['ok']);
        $this->assertStringContainsString('px wide', $checked[2]['problems'][0]['problem']);
        $this->assertFalse($checked[3]['found']);
        foreach (['desktop', 'mobile'] as $screen) {
            foreach (['closed', 'open'] as $state) {
                $this->assertFileExists($this->out."/spots/2-$screen-$state.jpg");
            }
        }
        // Only the screenshots of the position kept are left.
        $this->assertSame([], glob($this->out.'/spots/*-before.jpg') ?: []);
        $this->assertSame([], glob($this->out.'/spots/2-*-top.jpg') ?: []);
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
        (new Encoder)->encode($result->video, "$this->out/video.mp4", "$this->out/poster.jpg", $result->trimSeconds, Recorder::speed(), $result->timeline);
        $this->assertGreaterThan(10_000, filesize("$this->out/video.mp4"));

        // Played back at 2x: the file is as long as the result says, about
        // half the time the recording took.
        $this->assertEqualsWithDelta($result->seconds, $this->duration("$this->out/video.mp4"), 0.6);
        $this->assertFileExists("$this->out/poster.jpg");
    }

    public function test_a_check_plays_the_plan_quickly_without_recording_it(): void
    {
        $plan = new Plan('Post a job', 'Posting a job.', [
            $this->step('card', ['title' => 'Post a job', 'ms' => 5000]),
            $this->step('goto', ['url' => '/index.html']),
            $this->step('caption', ['title' => 'Start with the basics', 'ms' => 5000]),
            $this->step('type', ['target' => ['label' => 'Job title'], 'value' => 'Laravel developer']),
            $this->step('pause', ['ms' => 5000]),
            $this->step('click', ['target' => ['role' => 'button', 'name' => 'Publish job']]),
            $this->step('wait', ['target' => ['text' => 'Your job is live.']]),
        ]);

        $started = microtime(true);
        $result = (new Recorder)->verify($plan, $this->out);

        $this->assertTrue($result->ok, $result->summary());
        $this->assertNull($result->video);
        $this->assertSame([], File::glob("$this->out/*.webm"));
        // The 15 seconds of cards, captions and pauses aren't waited for.
        $this->assertLessThan(12, microtime(true) - $started);

        $broken = new Plan('x', 'y', [
            $this->step('goto', ['url' => '/index.html']),
            $this->step('click', ['target' => ['role' => 'button', 'name' => 'Save draft']]),
        ]);
        $result = (new Recorder)->verify($broken, $this->out, [], 'mobile');

        $this->assertFalse($result->ok);
        $this->assertSame(2, $result->failedStep);
        $this->assertSame('mobile', $result->device);
        $this->assertStringContainsString('Save draft', $result->error);
        $this->assertFileExists($result->screenshot);
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

        // For the full error view: Playwright's whole message, a screenshot, and what the browser said.
        $this->assertStringContainsString('Save draft', $result->errorFull);
        $this->assertFileExists($result->screenshot);
        $this->assertSame([1280, 800], array_slice(getimagesize($result->screenshot), 0, 2));
        $log = collect($result->browserLog);
        $this->assertSame('Draft autosave is off', $log->firstWhere('kind', 'warning')['text']);
        $this->assertStringContainsString('/missing-logo.png', $log->firstWhere('kind', 'failed')['text']);
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

        (new Encoder)->encode($result->video, "$this->out/mobile.mp4", "$this->out/mobile.jpg", $result->trimSeconds, Recorder::speed(), $result->timeline);
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

    public function test_spotlight_notes_each_step_and_zooms_in_on_it(): void
    {
        $plan = new Plan('Post a job', 'Posting a job.', [
            $this->step('goto', ['url' => '/index.html']),
            $this->step('type', ['target' => ['label' => 'Job title'], 'value' => 'Laravel developer']),
            $this->step('caption', ['title' => 'Then publish', 'ms' => 500]),
            $this->step('click', ['target' => ['role' => 'button', 'name' => 'Publish job']]),
        ]);
        $style = ['theme' => 'dark', 'accent' => '#e5693d', 'art' => 'abstract', 'app_theme' => 'dark', 'zoom' => true, 'highlight' => true];

        $result = (new Recorder)->record($plan, $this->out, $style);
        $this->assertTrue($result->ok, $result->summary());

        // The steps that used an element were noted with where it was; the caption pulled back.
        $elements = array_values(array_filter($result->focus, fn ($f) => empty($f['wide'])));
        $this->assertCount(2, $elements);
        $this->assertGreaterThan(0, $elements[0]['w']);
        $this->assertTrue(collect($result->focus)->contains(fn ($f) => ! empty($f['wide'])));

        $encoder = new Encoder;
        $encoder->encode($result->video, "$this->out/video.mp4", "$this->out/poster.jpg", $result->trimSeconds, Recorder::speed());
        $before = $this->duration("$this->out/video.mp4");

        $keys = Camera::keyframes($result->focus, 1280, 800);
        $this->assertGreaterThan(1, max(array_column($keys, 'z')));
        $this->assertTrue((new Recorder)->applyCamera("$this->out/video.mp4", $keys, 1280, 800, $encoder));
        // Same length after the camera pass.
        $this->assertEqualsWithDelta($before, $this->duration("$this->out/video.mp4"), 0.2);
    }

    public function test_checks_pages_before_any_ai_is_spent(): void
    {
        $port = $this->freePort();
        $server = Process::path(__DIR__.'/fixtures')->start(['php', '-S', "127.0.0.1:$port", 'check-router.php']);

        try {
            $this->waitForServer($port);
            config(['video-demo.recorder.base_url' => "http://127.0.0.1:$port"]);

            $checker = new Recorder;
            $problems = collect($checker->checkPages(['/', '/reports', '/settings', '/chart', '/missing', 'not-a-path'], 'guest'))->keyBy('path');
            // The language of each page that works, from its lang attribute.
            $this->assertSame(['/' => 'fr-FR'], $checker->pageLanguages);

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
            // The full error view lists the failed request.
            $this->assertSame(500, collect($result->browserLog)->firstWhere('kind', 'response')['status']);
        } finally {
            $server->stop();
        }
    }

    public function test_records_a_separate_frontend_signed_in_with_a_token_on_sample_data(): void
    {
        $apiPort = $this->freePort();
        $api = Process::path(__DIR__.'/fixtures')->start(['php', '-S', "127.0.0.1:$apiPort", 'api-router.php']);
        $spaPort = $this->freePort();
        $spa = Process::path(__DIR__.'/fixtures')->env(['API_URL' => "http://127.0.0.1:$apiPort"])->start(['php', '-S', "127.0.0.1:$spaPort", 'spa-router.php']);

        try {
            $this->waitForServer($apiPort);
            $this->waitForServer($spaPort);
            config([
                'video-demo.recorder.base_url' => "http://127.0.0.1:$apiPort",
                'video-demo.frontend.url' => "http://127.0.0.1:$spaPort",
                'video-demo.frontend.sign_in' => 'custom',
            ]);
            // Signed in through the package's link, on a sample database.
            $recorder = fn (?string $link) => tap(new class($link) extends Recorder
            {
                public function __construct(protected ?string $link) {}

                protected function loginPath(?string $recordAs, string $route = 'video-demo.studio.recorder-login'): ?string
                {
                    return $this->link;
                }
            }, fn ($r) => $r->sandbox = new Sandbox(sys_get_temp_dir().'/5-abcdefghijkl.sqlite'));

            // The token reaches the frontend's storage, and the headers its requests to the app,
            // so the dashboard works; a request that fails on the server is found before any AI is spent.
            $problems = collect($recorder('/video-demo/studio/_recorder/sign-in/1')->checkPages(['/', '/reports']))->keyBy('path');
            $this->assertSame(['/reports'], $problems->keys()->all());
            $this->assertSame('A request to the app failed', $problems['/reports']['problem']);
            $this->assertSame('GET /api/reports returned HTTP 500: Undefined variable $total', $problems['/reports']['detail']);

            // Signed out, the frontend sends the browser to its sign-in page itself.
            $problems = $recorder(null)->checkPages(['/'], 'guest');
            $this->assertSame('It needs signing in, but the video is recorded as a guest', $problems[0]['problem']);

            $result = $recorder('/video-demo/studio/_recorder/sign-in/1')->record(new Plan('x', 'x', [
                $this->step('goto', ['url' => '/']),
                $this->step('click', ['target' => ['role' => 'button', 'name' => 'Signed in as Sample Ada']]),
                // A label the page doesn't have leaves the first of the role, as before.
                $this->step('click', ['target' => ['role' => 'button', 'label' => 'Account']]),
            ]), $this->out, [], 'desktop', 'admin');
            $this->assertTrue($result->ok, (string) $result->error);

            // The AI can look at a page while it plans, signed in, and sees its labels.
            $page = $recorder('/video-demo/studio/_recorder/sign-in/1')->lookAtPage('/', 'admin');
            $this->assertTrue($page['ok'], (string) $page['error']);
            $this->assertStringContainsString('button "Signed in as Sample Ada"', $page['snapshot']);
            $this->assertStringContainsString('textbox "Job title"', $recorder('/video-demo/studio/_recorder/sign-in/1')->lookAtPage('/jobs/new', 'admin')['snapshot']);

            // A text box named by its label, among several, is the right one.
            $publish = fn (array $fields) => $recorder('/video-demo/studio/_recorder/sign-in/1')->record(new Plan('x', 'x', [
                $this->step('goto', ['url' => '/jobs/new']),
                ...$fields,
                $this->step('click', ['target' => ['role' => 'button', 'name' => 'Publish job']]),
                $this->step('wait', ['target' => ['text' => 'Published']]),
            ]), $this->out, [], 'desktop', 'admin');
            $result = $publish([
                $this->step('type', ['target' => ['role' => 'textbox', 'label' => 'Job title'], 'value' => 'Head of Support']),
                $this->step('type', ['target' => ['role' => 'textbox', 'label' => 'Location'], 'value' => 'Accra']),
            ]);
            $this->assertTrue($result->ok, (string) $result->error);

            // An optional endpoint's 404 and live validation's 422 while typing don't fail anything.
            $this->assertSame([], $recorder('/video-demo/studio/_recorder/sign-in/1')->checkPages(['/jobs/new'], 'admin'));

            // The app refusing what a step sent fails that step, so the video isn't made showing it.
            $result = $publish([$this->step('type', ['target' => ['role' => 'textbox', 'label' => 'Job title'], 'value' => 'Head of Support'])]);
            $this->assertSame(3, $result->failedStep);
            $this->assertStringContainsString('The app refused a request: POST /api/jobs returned HTTP 422: The location field is required.', (string) $result->error);
            $this->assertStringNotContainsString('..', (string) $result->error);

            // A step that waits in vain says what the app refused just before.
            $result = $recorder('/video-demo/studio/_recorder/sign-in/1')->record(new Plan('x', 'x', [
                $this->step('goto', ['url' => '/save']),
                $this->step('click', ['target' => ['role' => 'button', 'name' => 'Save']]),
                $this->step('wait', ['target' => ['text' => 'Saved'], 'ms' => 1000]),
            ]), $this->out, [], 'desktop', 'admin');
            $this->assertFalse($result->ok);
            $this->assertSame(2, $result->failedStep);
            $this->assertStringContainsString('The app refused a request: POST /api/save returned HTTP 419: CSRF token mismatch.', (string) $result->error);
        } finally {
            $api->stop();
            $spa->stop();
        }
    }

    public function test_signs_in_partway_through_and_accepts_the_cookie_banner_before_recording(): void
    {
        $port = $this->freePort();
        $server = Process::path(__DIR__.'/fixtures')->start(['php', '-S', "127.0.0.1:$port", 'members-router.php']);

        try {
            $this->waitForServer($port);
            config(['video-demo.recorder.base_url' => "http://127.0.0.1:$port", 'video-demo.recorder.login_as' => '7']);
            // The fixture's sign-in link stands in for the package's signed one.
            $recorder = new class extends Recorder
            {
                protected function signedPath(int|string $id, string $route): string
                {
                    return "/sign-me-in/$id";
                }
            };
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('email');
                $table->timestamps();
            });
            config(['auth.providers.users.model' => User::class]);
            User::forceCreate(['id' => 7, 'email' => 'member@example.com']);

            // A guest video: public page, then signed in as the default user for the members' page.
            $plan = new Plan('x', 'x', [
                $this->step('goto', ['url' => '/']),
                $this->step('sign_in', ['url' => '/dashboard']),
                $this->step('wait', ['target' => ['text' => 'Welcome back, member 7']]),
                // The banner was accepted before recording, so no page shows it.
                $this->step('wait', ['target' => ['text' => 'Necessary only'], 'ms' => 500]),
            ]);
            $result = $recorder->verify($plan, $this->out, [], 'desktop', 'guest');
            $this->assertFalse($result->ok);
            $this->assertSame(4, $result->failedStep, (string) $result->error);

            // Left alone, the banner shows on every page.
            config(['video-demo.recorder.cookie_banner' => 'leave']);
            $this->assertTrue($recorder->verify($plan, $this->out, [], 'desktop', 'guest')->ok);
            config(['video-demo.recorder.cookie_banner' => 'accept']);

            // Without signing in, the members' page says what to add.
            $result = $recorder->verify(new Plan('x', 'x', [$this->step('goto', ['url' => '/dashboard'])]), $this->out, [], 'desktop', 'guest');
            $this->assertStringContainsString('add a sign_in step before this page', (string) $result->error);
            // An email verification notice is named as such.
            $result = $recorder->verify(new Plan('x', 'x', [$this->step('goto', ['url' => '/unverified'])]), $this->out, [], 'desktop', 'guest');
            $this->assertStringContainsString('went to the email verification page instead', (string) $result->error);

            // And it records, signed in partway through.
            $result = $recorder->record(new Plan('x', 'x', array_slice($plan->steps, 0, 3)), $this->out, [], 'desktop', 'guest');
            $this->assertTrue($result->ok, (string) $result->error);
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

    public function test_makes_a_gif_and_a_vertical_copy_of_a_recording(): void
    {
        $plan = new Plan('Post a job', 'Posting a job.', [
            $this->step('goto', ['url' => '/index.html']),
            $this->step('type', ['target' => ['label' => 'Job title'], 'value' => 'Designer']),
            $this->step('click', ['target' => ['role' => 'button', 'name' => 'Publish job']]),
        ]);
        $result = (new Recorder)->record($plan, $this->out);
        $this->assertTrue($result->ok, $result->summary());

        config(['filesystems.disks.local.root' => $this->out.'/disk']);
        $video = Video::create([
            'title' => 'Post a job, from the jobs page to a live listing', 'prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'devices' => ['desktop'], 'style' => ['theme' => 'light', 'accent' => '#e5693d'],
            'recordings' => ['desktop' => ['seconds' => $result->seconds, 'chapters' => [], 'focus' => $result->focus]],
        ]);
        $mp4 = Recorder::workDir($video->workFile('desktop'));
        File::ensureDirectoryExists(dirname($mp4));
        (new Encoder)->encode($result->video, $mp4, Recorder::workDir($video->workFile('desktop', 'poster')), $result->trimSeconds, 1, $result->timeline);

        $exporter = app(Exporter::class);
        $gif = Recorder::workDir($exporter->export($video, 'gif'));
        $this->assertSame('GIF89a', substr(File::get($gif), 0, 6));
        $this->assertSame([800, 500], array_slice(getimagesize($gif), 0, 2));

        $vertical = Recorder::workDir($exporter->export($video, 'vertical'));
        $this->assertMatchesRegularExpression('/Video: h264.*1080x1920/', Process::run([config('video-demo.recorder.ffmpeg'), '-i', $vertical])->errorOutput());
        $this->assertEqualsWithDelta($result->seconds, $this->duration($vertical), 0.6);
        // Nothing is left behind but the copies.
        $this->assertSame(['gif.gif', 'vertical.mp4'], array_map('basename', File::files(dirname($vertical))));
    }

    protected function duration(string $file): float
    {
        $out = Process::run([config('video-demo.recorder.ffmpeg'), '-i', $file])->errorOutput();
        preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $out, $m);

        return $m ? $m[1] * 3600 + $m[2] * 60 + (float) $m[3] : 0.0;
    }
}
