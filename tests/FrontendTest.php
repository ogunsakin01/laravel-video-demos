<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Facades\VideoDemo;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\SignIn;
use Ogunsakin01\VideoDemo\Recording\Verifier;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Ogunsakin01\VideoDemo\Studio\Pages;

/**
 * A frontend served apart from the Laravel app, such as a Vue app on its own
 * address using the app as its API: where pages open, how the recorder signs
 * in (session, Sanctum token, JWT or the app's own way), and how its requests
 * reach the sample database. Without one, nothing changes.
 */
class FrontendTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        SignIn::using(null);
        $this->root = sys_get_temp_dir().'/video-demo-frontend-'.uniqid();
        config(['filesystems.disks.local.root' => $this->root]);

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        config(['auth.providers.users.model' => TokenUser::class]);
        TokenUser::forceCreate(['email' => 'ada@example.com', 'password' => 'x']);
    }

    protected function tearDown(): void
    {
        SignIn::using(null);
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /**
     * Get the settings the recorder gives its browser scripts.
     */
    protected function settings(?Sandbox $sandbox = null): array
    {
        $recorder = new class extends Recorder
        {
            public function settings(): array
            {
                return $this->browserSettings(['width' => 1280, 'height' => 800, 'touch' => false], 'ada@example.com');
            }
        };
        $recorder->sandbox = $sandbox;

        return $recorder->settings();
    }

    protected function sandbox(): Sandbox
    {
        File::ensureDirectoryExists(Sandbox::dir());
        File::put($path = Sandbox::dir().'/5-abcdefghijkl.sqlite', '');

        return new Sandbox($path);
    }

    public function test_without_a_frontend_the_recorder_signs_in_and_opens_pages_as_before(): void
    {
        $settings = $this->settings();

        $this->assertNull($settings['frontendUrl']);
        $this->assertNull($settings['signInPath']);
        $this->assertStringStartsWith('/video-demo/studio/_recorder/login/', $settings['loginPath']);
        // A token sign-in set without a frontend is ignored: the app's own pages use its session.
        config(['video-demo.frontend.sign_in' => 'sanctum-token']);
        $this->assertNull($this->settings()['signInPath']);
        $this->assertStringStartsWith('/video-demo/studio/_recorder/login/', $this->settings()['loginPath']);
        // The sample database is still reached by its cookie, not the header.
        $settings = $this->settings($this->sandbox());
        $this->assertNull($settings['sandboxHeader']);
        $this->assertSame(Sandbox::COOKIE, $settings['cookies'][0]['name']);
    }

    public function test_with_a_frontend_pages_open_there_and_tokens_come_from_a_signed_link(): void
    {
        config(['video-demo.frontend.url' => 'http://localhost:5173', 'video-demo.frontend.sign_in' => 'sanctum-token']);
        $settings = $this->settings();

        $this->assertSame('http://localhost:5173', $settings['frontendUrl']);
        $this->assertNull($settings['loginPath']);
        $this->assertStringStartsWith('/video-demo/studio/_recorder/sign-in/', $settings['signInPath']);
        // On sample data, the frontend's requests to the app carry the header.
        $sandbox = $this->sandbox();
        $this->assertSame(['name' => Sandbox::HEADER, 'value' => $sandbox->cookie()['value']], $this->settings($sandbox)['sandboxHeader']);

        // Sanctum's cookie sign-in, for a frontend on the same site, is the app's own session.
        config(['video-demo.frontend.sign_in' => 'session']);
        $this->assertStringStartsWith('/video-demo/studio/_recorder/login/', $this->settings()['loginPath']);
        $this->assertNull($this->settings()['signInPath']);
    }

    public function test_the_sign_in_link_gives_the_browser_a_token_or_what_the_app_returns(): void
    {
        config(['video-demo.frontend.sign_in' => 'sanctum-token', 'video-demo.frontend.token_key' => 'auth_token']);
        $link = fn ($user = 1) => URL::temporarySignedRoute('video-demo.studio.recorder-sign-in', now()->addMinutes(5), ['user' => $user], absolute: false);

        // Only with a valid signature, and only for a user who exists.
        $this->get('/video-demo/studio/_recorder/sign-in/1')->assertForbidden();
        $this->get($link(999))->assertNotFound();

        // A Sanctum token for an hour, kept where the frontend reads it, never cached.
        $response = $this->get($link())->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['auth_token' => 'token-for-ada@example.com'], $response->json('localStorage'));
        $this->assertSame(['video-demo', 60], [TokenUser::$made['name'], (int) round(now()->diffInMinutes(TokenUser::$made['expires']))]);

        // The app's own way: any mix of storage, cookies and headers.
        config(['video-demo.frontend.sign_in' => 'custom']);
        VideoDemo::signInUsing(fn ($user) => SignIn::header('Authorization', 'Bearer passport-'.$user->id)->withCookie('team', '7')->withSessionStorage('tenant', 'acme'));
        $this->assertSame([
            'cookies' => [['name' => 'team', 'value' => '7']],
            'localStorage' => [],
            'sessionStorage' => ['tenant' => 'acme'],
            'headers' => ['Authorization' => 'Bearer passport-1'],
        ], $this->get($link())->assertOk()->json());

        // A preset that can't work here says why.
        config(['video-demo.frontend.sign_in' => 'jwt']);
        $this->assertStringContainsString('No JWT package was found', $this->get($link())->assertStatus(422)->json('error'));
        // With jwt-auth installed, its token.
        $this->app->instance('tymon.jwt.auth', new class
        {
            public function fromUser($user): string
            {
                return 'jwt-for-'.$user->email;
            }
        });
        $this->assertSame(['auth_token' => 'jwt-for-ada@example.com'], $this->get($link())->assertOk()->json('localStorage'));
        config(['video-demo.frontend.sign_in' => 'sanctum-token', 'auth.providers.users.model' => User::class]);
        $this->assertStringContainsString('Laravel\Sanctum\HasApiTokens', $this->get($link())->assertStatus(422)->json('error'));
        config(['video-demo.frontend.sign_in' => 'custom']);
        SignIn::using(null);
        $this->assertStringContainsString('no VideoDemo::signInUsing() callback', $this->get($link())->assertStatus(422)->json('error'));
        config(['video-demo.frontend.sign_in' => 'sanctum']);
        $this->assertSame("video-demo.frontend.sign_in [sanctum] isn't a way to sign in. Use session, sanctum-token, jwt or custom.", $this->get($link())->assertStatus(422)->json('error'));
    }

    public function test_the_header_reaches_the_sample_database_like_the_cookie(): void
    {
        $sandbox = $this->sandbox();
        $value = $sandbox->cookie()['value'];
        $request = fn (array $headers = [], array $cookies = []) => Request::create('/api/jobs', 'GET', cookies: $cookies, server: collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all());

        $this->assertSame(Sandbox::dir().'/5-abcdefghijkl.sqlite', Sandbox::fromRequest($request([Sandbox::HEADER => $value])));
        $this->assertSame(Sandbox::dir().'/5-abcdefghijkl.sqlite', Sandbox::fromRequest($request(cookies: [Sandbox::COOKIE => $value])));
        // Tampered with, or not sent, it opens nothing.
        $this->assertNull(Sandbox::fromRequest($request([Sandbox::HEADER => $value.'0'])));
        $this->assertNull(Sandbox::fromRequest($request()));
    }

    public function test_the_sign_in_link_exists_only_where_the_studio_does(): void
    {
        $this->assertTrue(Route::has('video-demo.studio.recorder-sign-in'));
    }

    public function test_laravel_routes_are_not_offered_as_a_frontends_pages(): void
    {
        Route::get('/jobs', fn () => 'Jobs');
        $this->assertArrayHasKey('/jobs', app(Pages::class)->candidates());

        // They are its API.
        config(['video-demo.frontend.url' => 'http://localhost:5173']);
        $this->assertSame([], app(Pages::class)->candidates());
    }

    public function test_the_ai_reads_the_frontends_code_under_frontend_with_the_same_rules(): void
    {
        $app = $this->root.'/app-root';
        File::ensureDirectoryExists("$app/app");
        File::put("$app/app/JobController.php", '<?php // jobs');
        $web = $this->root.'/web';
        File::ensureDirectoryExists("$web/src/views");
        File::put("$web/src/router.ts", "{ path: '/jobs/new', component: NewJob }");
        File::put("$web/src/views/NewJob.vue", '<label for="t">Job title</label>');
        File::put("$web/src/.env", 'VITE_SECRET=x');
        File::put("$web/secret.txt", 'outside src');
        config(['video-demo.ai.code.paths' => ['app']]);

        // What the AI is told when it may not read something.
        $attempt = fn (CodeTools $tools, string $tool, array $input) => rescue(fn () => $tools->run($tool, $input), fn ($e) => $e->getMessage(), false);

        // Without a frontend there is no frontend/ to read.
        $this->assertStringContainsString('does not exist', $attempt(new CodeTools($app), 'list_files', ['path' => 'frontend/src']));

        config(['video-demo.frontend.path' => '../web']);
        $tools = new CodeTools($app);
        $this->assertStringContainsString('Readable folders: app, frontend/src.', json_encode($tools->definitions(), JSON_UNESCAPED_SLASHES));
        $this->assertSame("frontend/src/router.ts\nfrontend/src/views/NewJob.vue", $tools->run('list_files', ['path' => 'frontend/src']));
        $this->assertStringContainsString("path: '/jobs/new'", $tools->run('read_file', ['path' => 'frontend/src/router.ts']));
        $this->assertStringContainsString('frontend/src/views/NewJob.vue:1', $tools->run('search_code', ['pattern' => 'Job title']));
        // The same rules: secrets files, and anything outside the folders allowed.
        $this->assertStringContainsString('may contain secrets', $attempt($tools, 'read_file', ['path' => 'frontend/src/.env']));
        $this->assertStringContainsString('outside the folders you can read', $attempt($tools, 'read_file', ['path' => 'frontend/secret.txt']));
        $this->assertStringContainsString('outside the folders you can read', $attempt($tools, 'read_file', ['path' => 'frontend/src/../secret.txt']));
        $this->assertStringContainsString('outside the folders you can read', $attempt($tools, 'read_file', ['path' => 'frontend/../app-root/app/JobController.php']));
        // What it read is noted, so changes to it flag the video.
        $this->assertContains('frontend/src/router.ts', $tools->takeReadFiles());
    }

    public function test_changes_in_the_frontends_repository_flag_the_videos_made_from_them(): void
    {
        config(['video-demo.frontend.path' => '../web']);
        Process::fake(fn ($process) => Process::result(str_ends_with((string) $process->path, 'web') ? "src/views/NewJob.vue\n" : "app/Http/Controllers/JobController.php\n"));

        $changed = Verifier::changedFiles('main', 'origin/main');
        $this->assertSame(['app/Http/Controllers/JobController.php', 'frontend/src/views/NewJob.vue'], $changed);
        $this->assertSame(['frontend/src/views/NewJob.vue'], Verifier::changedFiles(null, 'origin/main'));

        $video = Video::create(['prompt' => 'x', 'target_seconds' => 30, 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'status' => VideoStatus::Ready,
            'source_files' => ['frontend/src/views/NewJob.vue'], 'plan' => ['title' => 'x', 'summary' => '', 'steps' => [['action' => 'goto', 'url' => '/jobs/new']]]]);
        $this->assertSame(['frontend/src/views/NewJob.vue'], Verifier::touched($video, $changed));

        config(['video-demo.frontend.path' => null]);
        $this->expectExceptionMessage('There is no frontend to compare');
        Verifier::changedFiles(null, 'origin/main');
    }
}

/**
 * A user with Sanctum's createToken(), without needing Sanctum.
 */
class TokenUser extends User
{
    protected $table = 'users';

    /** @var array<string, mixed> */
    public static array $made = [];

    public function createToken(string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null): object
    {
        static::$made = ['name' => $name, 'expires' => $expiresAt];

        return (object) ['plainTextToken' => 'token-for-'.$this->email];
    }
}
