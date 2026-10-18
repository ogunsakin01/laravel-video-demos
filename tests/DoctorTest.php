<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/**
 * video-demo:doctor: everything making videos needs, checked, with the fix for
 * whatever isn't right. It changes nothing and costs no AI.
 */
class DoctorTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/video-demo-doctor-'.uniqid();
        File::ensureDirectoryExists("$this->root/node_modules/playwright");
        File::put("$this->root/node_modules/playwright/package.json", '{}');
        File::put("$this->root/chromium", '');
        config([
            'video-demo.recorder.node_modules' => "$this->root/node_modules",
            'video-demo.ai.api_key' => 'sk-test',
            'video-demo.sample_data.default' => false,
            'video-demo.storage.disk' => 'local',
            'filesystems.disks.local.root' => "$this->root/storage",
            // A queue fit for long videos, whatever the test app's default queue is (sync on a fresh install).
            'video-demo.queue.connection' => 'video-demo-test',
            'queue.connections.video-demo-test' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'video-demo', 'retry_after' => 7200],
        ]);
        Http::fake(['*' => Http::response('ok')]);
        // Node 22, its Chromium installed, and ffmpeg.
        Process::fake(fn ($process) => match (true) {
            in_array('--version', (array) $process->command, true) => Process::result('v22.6.0'),
            in_array('-e', (array) $process->command, true) => Process::result("$this->root/chromium"),
            in_array('-version', (array) $process->command, true) => Process::result("ffmpeg version 7.1\nmore"),
            default => Process::result('', '', 1),
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /**
     * Run the doctor, returning its exit code and checks keyed by what was checked.
     */
    protected function doctor(): array
    {
        $code = Artisan::call('video-demo:doctor', ['--json' => true]);

        return [$code, collect(json_decode(Artisan::output(), true)['checks'])->keyBy('check')->all()];
    }

    public function test_a_machine_ready_to_make_videos_passes(): void
    {
        [$code, $checks] = $this->doctor();

        $this->assertSame(0, $code);
        $this->assertSame('ok', $checks['The AI']['status']);
        $this->assertSame(['ok', 'v22.6.0'], [$checks['Node.js']['status'], $checks['Node.js']['found']]);
        $this->assertSame(['ok', 'ffmpeg version 7.1'], [$checks['ffmpeg']['status'], $checks['ffmpeg']['found']]);
        $this->assertSame('ok', $checks['The browser']['status']);
        $this->assertStringContainsString('answers (HTTP 200)', $checks['The app']['found']);
        // Nothing to set up for a frontend that isn't there.
        $this->assertArrayNotHasKey('Signing in to the frontend', $checks);

        $this->artisan('video-demo:doctor')->expectsOutputToContain('Everything needed is set up.')->assertSuccessful();
    }

    public function test_what_is_missing_fails_with_how_to_fix_it(): void
    {
        config(['video-demo.ai.api_key' => null, 'video-demo.recorder.node_modules' => "$this->root/nowhere", 'video-demo.storage.disk' => 'public',
            'video-demo.frontend.url' => 'http://localhost:5173', 'video-demo.frontend.sign_in' => 'jwt']);
        Http::fake(['localhost:5173*' => fn () => throw new ConnectionException('refused'), '*' => Http::response('ok')]);

        [$code, $checks] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertSame('fail', $checks['The AI']['status']);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $checks['The AI']['fix']);
        $this->assertSame(['fail', 'Run php artisan video-demo:install.'], [$checks['Playwright']['status'], $checks['Playwright']['fix']]);
        $this->assertSame(['fail', 'Nothing answers at http://localhost:5173'], [$checks['The separate frontend']['status'], $checks['The separate frontend']['found']]);
        $this->assertSame('fail', $checks['Signing in to the frontend']['status']);
        $this->assertStringContainsString('php-open-source-saver/jwt-auth', $checks['Signing in to the frontend']['fix']);
        // A note, not a failure: fine here, but production won't see these videos.
        $this->assertSame('warn', $checks['Where published videos go']['status']);

        $this->artisan('video-demo:doctor')->expectsOutputToContain('to fix before videos can be made.')->assertFailed();
    }

    public function test_the_default_user_must_exist(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->timestamps();
        });
        config(['auth.providers.users.model' => User::class, 'video-demo.recorder.login_as' => 'nobody@example.com']);

        [$code, $checks] = $this->doctor();

        $this->assertSame([1, 'fail'], [$code, $checks['The default user']['status']]);
        $this->assertStringContainsString('nobody@example.com', $checks['The default user']['found']);
    }

    public function test_the_studio_shows_what_must_be_fixed(): void
    {
        config(['video-demo.ai.api_key' => null]);

        $problems = $this->getJson(route('video-demo.studio.setup'))->assertOk()->json('problems');
        $this->assertSame(['The AI'], array_column($problems, 'check'));
        // The page being open shows the app answers, so it isn't asked again from the studio.
        Http::assertNothingSent();

        // Kept for ten minutes, as some checks start programs; "Check again" checks now.
        config(['video-demo.ai.api_key' => 'sk-test']);
        $this->assertCount(1, $this->getJson(route('video-demo.studio.setup'))->json('problems'));
        $this->assertSame([], $this->getJson(route('video-demo.studio.setup').'?fresh=1')->json('problems'));

        $this->get(route('video-demo.studio.index'))->assertSee('data-setup', false);
        $this->get(route('video-demo.studio.create'))->assertSee('data-setup', false);
    }
}
