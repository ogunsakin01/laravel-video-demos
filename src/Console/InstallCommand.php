<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Library\Restorer;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Ogunsakin01\VideoDemo\Support\PackageConfig;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Ogunsakin01\VideoDemo\Voice\Voices;
use Throwable;

/**
 * Sets the package up in one go, and is safe to run again after each
 * upgrade: the package's migrations, Playwright and Chromium for
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
        {--with-voice : Also install the free local voice for voice-overs (about 500 MB, once). It is installed anyway when a video or style here uses it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up or upgrade the package: migrations, the recorder\'s browser and the sample database';

    /**
     * The Playwright version the recorder is tested with.
     */
    protected const PLAYWRIGHT = '1.64.0';

    /**
     * Remove the app's copy of the config when nobody edited it: older
     * versions of this command made it. It holds nothing of the app's, and
     * settings now come from the package and .env. An edited copy stays;
     * what it lacks comes from the package.
     */
    protected function removeUneditedConfig(): void
    {
        $file = config_path('video-demo.php');
        $version = PackageConfig::uneditedVersion($file);
        if ($version === null) {
            return;
        }

        File::delete($file);
        if ($this->laravel instanceof CachesConfiguration && $this->laravel->configurationIsCached()) {
            $this->callSilently('config:clear');
        }
        $this->components->info(sprintf(
            'Removed config/video-demo.php: an unedited copy of the package\'s config%s, made by an older version of this command. Settings come from the package and .env now; publish it again with --tag=video-demo-config to change more.',
            $version === 'current' ? '' : " from $version",
        ));
    }

    /**
     * Bring back into the studio the published videos its database no longer
     * has, such as after the database was reset, so they can be managed again.
     */
    protected function restorePublishedVideos(): void
    {
        if (! VideoDemoServiceProvider::studioEnabled()) {
            return;
        }

        try {
            $restored = app(Restorer::class)->restore();
        } catch (Throwable $e) {
            $this->components->warn('Could not check the library for videos the studio lost: '.$e->getMessage());

            return;
        }

        if ($restored !== []) {
            $this->components->info(sprintf(
                'Brought back %d published %s the studio\'s database no longer had: %s.',
                count($restored), count($restored) === 1 ? 'video' : 'videos', collect($restored)->pluck('title')->implode(', '),
            ));
        }
    }

    /**
     * Whether the app's default voice, a video or a style uses a local voice.
     */
    protected function localVoiceUsed(): bool
    {
        if (str_starts_with((string) config('video-demo.voice.default'), 'kokoro:')) {
            return true;
        }

        try {
            return Video::query()->where('voice', 'like', 'kokoro:%')->exists()
                || VideoStyle::query()->where('voice', 'like', 'kokoro:%')->exists();
        } catch (Throwable) {
            // The tables aren't there, as when the migrations failed.
            return false;
        }
    }

    /**
     * The GIF encoder the recorder uses for GIF copies of videos.
     */
    protected const GIFENC = '1.0.3';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // The config isn't copied into the app: everything is set from .env.
        // Publish it with --tag=video-demo-config to change more.
        $this->removeUneditedConfig();

        // Laravel ignores only the storage folders it makes itself, so keep
        // the package's tools out of the app's repository and its deploys.
        $this->ignoreInGit(storage_path('video-demo'));

        // Only the package's own migrations: the app's are its own business.
        $this->components->task('Running the package\'s migrations', fn () => $this->callSilently('migrate', [
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ]) === 0);

        $this->restorePublishedVideos();

        if (! $this->option('no-browser')) {
            $this->installBrowser();
        }

        if (! $this->option('no-sample-data')) {
            $this->buildSampleDatabase();
        }

        if ($this->option('with-voice')) {
            $this->call('video-demo:install-voice');
        } elseif (! Voices::localInstalled() && $this->localVoiceUsed()) {
            // A teammate's machine or a CI runner gets the voice its videos are made with.
            $this->components->info('Installing the local voice: videos or styles here use it.');
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
