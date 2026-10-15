<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Throwable;

/**
 * Sets the package up in one go, and is safe to run again after each
 * upgrade: the config, the package's migrations, Playwright and Chromium for
 * the recorder, the sample database, and a restart of the queue workers. The
 * recorder's files are kept out of the app's git repository.
 */
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:install
        {--no-browser : Skip installing Playwright and Chromium}
        {--no-sample-data : Skip building the sample database}
        {--with-voice : Also install the free local voice for voice-overs (about 250 MB, once)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up or upgrade the package: config, migrations, the recorder\'s browser and the sample database';

    /**
     * The Playwright version the recorder is tested with.
     */
    protected const PLAYWRIGHT = '1.64.0';

    /**
     * The GIF encoder the recorder uses for GIF copies of videos.
     */
    protected const GIFENC = '1.0.3';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // An existing config is left as it is.
        $this->call('vendor:publish', ['--tag' => 'video-demo-config']);

        // Laravel ignores only the storage folders it makes itself, so keep
        // the package's tools out of the app's repository and its deploys.
        $this->ignoreInGit(storage_path('video-demo'));

        // Only the package's own migrations: the app's are its own business.
        $this->components->task('Running the package\'s migrations', fn () => $this->callSilently('migrate', [
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ]) === 0);

        if (! $this->option('no-browser')) {
            $this->installBrowser();
        }

        if (! $this->option('no-sample-data')) {
            $this->buildSampleDatabase();
        }

        if ($this->option('with-voice')) {
            $this->call('video-demo:install-voice');
        }

        // Running workers keep the code they started with until they restart.
        $this->callSilently('queue:restart');

        $this->newLine();
        $this->components->info('Next: set ANTHROPIC_API_KEY (or another provider\'s key) and VIDEO_DEMO_LOGIN_AS in .env, start a worker with php artisan queue:work video-demo, and open /'.trim(config('video-demo.studio.prefix'), '/').'. Run this command again after each upgrade.');

        return self::SUCCESS;
    }

    /**
     * Install Playwright and Chromium for the recorder, and check for ffmpeg.
     */
    protected function installBrowser(): void
    {
        $modules = config('video-demo.recorder.node_modules');
        $dir = dirname($modules);
        File::ensureDirectoryExists($dir);
        // A recorder installed elsewhere in the app is kept out of git too.
        if (str_starts_with(realpath($dir) ?: $dir, base_path().'/') && ! str_starts_with($dir, storage_path('video-demo'))) {
            $this->ignoreInGit($dir);
        }
        if (! File::exists("$dir/package.json")) {
            File::put("$dir/package.json", json_encode(['private' => true, 'name' => 'video-demo-recorder'], JSON_PRETTY_PRINT));
        }

        $this->components->task('Installing Playwright '.self::PLAYWRIGHT.' and the GIF encoder', fn () => $this->runInDir(
            [config('video-demo.recorder.npm'), 'install', '--no-audit', '--no-fund', 'playwright@'.self::PLAYWRIGHT, 'gifenc@'.self::GIFENC],
            $dir,
        ));

        $this->components->task('Installing Chromium for Playwright', fn () => $this->runInDir(
            [config('video-demo.recorder.node'), "$modules/playwright/cli.js", 'install', 'chromium'],
            $dir,
        ));

        try {
            $hasFfmpeg = Process::run([config('video-demo.recorder.ffmpeg'), '-version'])->successful();
        } catch (Throwable) {
            $hasFfmpeg = false;
        }
        if (! $hasFfmpeg) {
            $this->components->warn('ffmpeg could not be run. Install it (brew install ffmpeg, apt install ffmpeg), or set VIDEO_DEMO_FFMPEG to the full path of a copy, or of a wrapper script for a bundled copy: https://github.com/ogunsakin01/laravel-video-demos/wiki/Installation');
        }
    }

    /**
     * Build the sample database videos can be recorded on. A failure is only
     * a warning: everything else works without it.
     */
    protected function buildSampleDatabase(): void
    {
        $base = null;
        $error = null;
        $this->components->task('Building the sample database (your migrations and seeders, on a separate SQLite file)', function () use (&$base, &$error) {
            try {
                $base = Sandbox::base();
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            return $base !== null;
        });

        if ($error !== null) {
            $this->components->warn("Recording on sample data won't work until this is fixed: $error Then run php artisan video-demo:sample-database.");

            return;
        }

        foreach ($base['info']['warnings'] ?? [] as $warning) {
            $this->components->warn($warning);
        }
        foreach ($base['info']['users'] ?? [] as $type => $user) {
            $this->components->twoColumnDetail("Sample $type user", $user);
        }
    }

    /**
     * Keep a folder out of git with a .gitignore that ignores everything in
     * it, as Laravel does for its own storage folders. An existing one is
     * left as it is.
     */
    protected function ignoreInGit(string $dir): void
    {
        File::ensureDirectoryExists($dir);
        if (! File::exists("$dir/.gitignore")) {
            File::put("$dir/.gitignore", "*\n!.gitignore\n");
        }
    }

    /**
     * Run a command in the given directory, printing its error output when it fails.
     */
    protected function runInDir(array $command, string $dir): bool
    {
        $result = Process::path($dir)->timeout(900)->run($command);

        if ($result->failed()) {
            $this->components->error(trim($result->errorOutput()) ?: trim($result->output()));
        }

        return $result->successful();
    }
}
