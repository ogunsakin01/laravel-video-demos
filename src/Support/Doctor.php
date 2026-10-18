<?php

namespace Ogunsakin01\VideoDemo\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Recording\RecordAs;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\SignIn;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Ogunsakin01\VideoDemo\Voice\Voices;
use Throwable;

/**
 * Checks everything making videos needs on this machine, and says how to fix
 * what isn't right: the studio, the AI key, the app's address, a separate
 * frontend and its sign-in, Node, Playwright and its browser, ffmpeg, the
 * queue and a worker, the user to record as, sample data, where published
 * videos go, and the voice. It changes nothing and costs no AI. Used by
 * video-demo:doctor and the studio's setup notice.
 */
class Doctor
{
    /**
     * The checks so far: status (ok, warn or fail), what was checked, what was found and how to fix it.
     *
     * @var list<array{status: string, check: string, found: string, fix: ?string}>
     */
    protected array $checks = [];

    /**
     * Run every check. From a web request ($web) the app's own address isn't
     * checked: the page being open shows it answers, and a one-at-a-time
     * server (php artisan serve) couldn't answer a request to itself.
     *
     * @return list<array{status: string, check: string, found: string, fix: ?string}>
     */
    public function run(bool $web = false): array
    {
        $this->checks = [];
        $this->studio();
        $this->ai();
        if (! $web) {
            $this->address('The app', (string) config('video-demo.recorder.base_url'), 'Serve the app there (Herd, Valet or php artisan serve), or set VIDEO_DEMO_BASE_URL to where it runs.');
        }
        if ($frontend = config('video-demo.frontend.url')) {
            $this->address('The separate frontend', (string) $frontend, 'Start the frontend (npm run dev), or set VIDEO_DEMO_FRONTEND_URL to where it runs.');
            $this->signIn();
        }
        $this->node();
        $this->ffmpeg();
        $this->queue();
        $this->users();
        $this->storage();
        $this->voice();

        return $this->checks;
    }

    /**
     * Note a check's result.
     */
    protected function add(string $status, string $check, string $found, ?string $fix = null): void
    {
        $this->checks[] = ['status' => $status, 'check' => $check, 'found' => $found, 'fix' => $fix];
    }

    protected function studio(): void
    {
        VideoDemoServiceProvider::studioEnabled()
            ? $this->add('ok', 'The studio', 'Enabled here')
            : $this->add('fail', 'The studio', 'Disabled here, so videos can\'t be made or signed in to', 'Make videos where APP_ENV is local, or set VIDEO_DEMO_STUDIO=true (with a viewVideoDemoStudio gate outside your own machine).');
    }

    protected function ai(): void
    {
        $model = Pricing::defaultModel();
        $label = Pricing::model($model)['label'] ?? $model;
        Drivers::hasKey($model)
            ? $this->add('ok', 'The AI', "$label, with its key set")
            : $this->add('fail', 'The AI', "No key for $label (".Drivers::provider($model).')', Drivers::provider($model) === 'anthropic'
                ? 'Set ANTHROPIC_API_KEY in .env, or VIDEO_DEMO_MODEL to a model whose provider has a key.'
                : 'Set the '.strtoupper(Drivers::provider($model)).'_API_KEY in .env (read by config/prism.php).');
    }

    /**
     * Check an address answers. Any answer counts, even a redirect or a 404:
     * only "nothing there" is a problem.
     */
    protected function address(string $check, string $url, string $fix): void
    {
        try {
            $status = Http::timeout(8)->withoutRedirecting()->withOptions(['verify' => ! config('video-demo.recorder.ignore_https_errors')])->get($url)->status();
            $this->add('ok', $check, "$url answers (HTTP $status)");
        } catch (Throwable $e) {
            $this->add('fail', $check, "Nothing answers at $url", $fix);
        }
    }

    protected function signIn(): void
    {
        $preset = (string) config('video-demo.frontend.sign_in', 'session');
        $model = RecordAs::model();
        [$status, $found, $fix] = match ($preset) {
            'session' => ['ok', 'The app\'s login session (also Sanctum\'s cookie sign-in)', null],
            'sanctum-token' => $model && method_exists($model, 'createToken')
                ? ['ok', 'A Sanctum token in localStorage under "'.config('video-demo.frontend.token_key').'"', null]
                : ['fail', 'sanctum-token, but the user model has no createToken()', 'Add Laravel\Sanctum\HasApiTokens to the user model.'],
            'jwt' => app()->bound('tymon.jwt.auth')
                ? ['ok', 'A JWT in localStorage under "'.config('video-demo.frontend.token_key').'"', null]
                : ['fail', 'jwt, but no JWT package is installed', 'Install php-open-source-saver/jwt-auth, or choose another VIDEO_DEMO_SIGN_IN.'],
            'custom' => SignIn::hasCallback()
                ? ['ok', 'The app\'s own VideoDemo::signInUsing()', null]
                : ['fail', 'custom, but no VideoDemo::signInUsing() callback is registered', 'Register one in a service provider.'],
            default => ['fail', "\"$preset\" isn't a way to sign in", 'Set VIDEO_DEMO_SIGN_IN to session, sanctum-token, jwt or custom.'],
        };
        $this->add($status, 'Signing in to the frontend', $found, $fix);
    }

    protected function node(): void
    {
        $node = (string) config('video-demo.recorder.node', 'node');
        $version = $this->program([$node, '--version']);
        if ($version === null) {
            $this->add('fail', 'Node.js', "\"$node\" could not be run", 'Install Node.js 18 or later, or set VIDEO_DEMO_NODE to its full path.');

            return;
        }
        (int) ltrim($version, 'v') >= 18
            ? $this->add('ok', 'Node.js', $version)
            : $this->add('fail', 'Node.js', "$version is too old", 'Install Node.js 18 or later.');

        $modules = (string) config('video-demo.recorder.node_modules');
        if (! is_file("$modules/playwright/package.json")) {
            $this->add('fail', 'Playwright', 'Not installed in '.str_replace(base_path().'/', '', $modules), 'Run php artisan video-demo:install.');

            return;
        }
        $this->add('ok', 'Playwright', 'Installed');

        if ($channel = config('video-demo.recorder.channel')) {
            $this->add('ok', 'The browser', "Installed $channel (VIDEO_DEMO_BROWSER_CHANNEL)");

            return;
        }
        $path = $this->program([$node, '-e', 'process.stdout.write(require("playwright").chromium.executablePath())'], ['NODE_PATH' => $modules]);
        $path !== null && is_file($path)
            ? $this->add('ok', 'The browser', 'Chromium is installed')
            : $this->add('fail', 'The browser', 'Chromium is not installed', 'Run php artisan video-demo:install.');
    }

    protected function ffmpeg(): void
    {
        $ffmpeg = (string) config('video-demo.recorder.ffmpeg', 'ffmpeg');
        $version = $this->program([$ffmpeg, '-version']);
        $version !== null
            ? $this->add('ok', 'ffmpeg', strtok($version, "\n") ?: 'Installed')
            : $this->add('fail', 'ffmpeg', "\"$ffmpeg\" could not be run", 'Install it (brew install ffmpeg, apt install ffmpeg), or set VIDEO_DEMO_FFMPEG to its full path.');
    }

    protected function queue(): void
    {
        if ($warning = VideoDemoServiceProvider::queueWarning()) {
            $this->add('fail', 'The queue', $warning, null);

            return;
        }
        $this->add('ok', 'The queue', 'Videos run on the '.VideoDemoServiceProvider::queueConnection().' connection');

        match (Worker::running()) {
            true => $this->add('ok', 'A queue worker', 'Running'),
            false => $this->add('warn', 'A queue worker', 'None is running, so new videos would wait', 'Start one: php artisan video-demo:work'),
            null => $this->add('warn', 'A queue worker', 'Can\'t tell with the array cache', 'Use a shared cache (file, database, redis) so the studio can see workers.'),
        };
    }

    protected function users(): void
    {
        $types = array_keys(RecordAs::types());
        $types !== []
            ? $this->add('ok', 'Types of user', implode(', ', array_map('strval', $types)))
            : $this->add('warn', 'Types of user', 'None found, so videos can only be recorded signed in as one user or as a guest', 'List them in video-demo.recorder.user_types, or give users roles (spatie/laravel-permission, a role column…).');

        try {
            $user = Recorder::loginUser();
            $user
                ? $this->add('ok', 'The default user', (string) config('video-demo.recorder.login_as').' (for videos on your own data)')
                : $this->add('warn', 'The default user', 'VIDEO_DEMO_LOGIN_AS isn\'t set, so videos on your own data are recorded signed out', 'Set VIDEO_DEMO_LOGIN_AS to a user\'s email or id.');
        } catch (Throwable $e) {
            $this->add('fail', 'The default user', $e->getMessage(), 'Set VIDEO_DEMO_LOGIN_AS to a user that exists.');
        }

        if (config('video-demo.sample_data.default', true)) {
            is_file(Sandbox::dir().'/base-'.Sandbox::fingerprint().'.sqlite')
                ? $this->add('ok', 'Sample data', 'The sample database is built and up to date')
                : $this->add('warn', 'Sample data', 'The sample database isn\'t built yet, so the first video builds it (as long as your migrations and seeders take)', 'Build it now: php artisan video-demo:sample-database');
        }
    }

    protected function storage(): void
    {
        $disk = (string) config('video-demo.storage.disk');
        if (! config("filesystems.disks.$disk")) {
            $this->add('fail', 'Where published videos go', "There is no [$disk] disk", 'Set VIDEO_DEMO_DISK to a disk in config/filesystems.php, such as s3, or video-demo-repo.');

            return;
        }
        $disk === 'public'
            ? $this->add('warn', 'Where published videos go', 'The public disk, on this machine: production won\'t see videos published here', 'For production, set VIDEO_DEMO_DISK to a disk both reach (s3, r2), or video-demo-repo to commit them with your code.')
            : $this->add('ok', 'Where published videos go', "The [$disk] disk");
    }

    protected function voice(): void
    {
        $voice = config('video-demo.voice.default');
        if (! $voice) {
            return;
        }
        Voices::available((string) $voice)
            ? $this->add('ok', 'The default voice', Voices::label((string) $voice))
            : $this->add('warn', 'The default voice', "$voice isn't available here", str_starts_with((string) $voice, 'kokoro:') ? 'Run php artisan video-demo:install-voice.' : 'Set its provider\'s API key, or change VIDEO_DEMO_VOICE.');
    }

    /**
     * Run a program, returning what it printed, or null when it can't be run.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    protected function program(array $command, array $env = []): ?string
    {
        try {
            $result = Process::timeout(20)->env($env)->run($command);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? trim($result->output()) : null;
    }
}
