<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Publishes the config and installs Playwright and Chromium for the recorder.
 */
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:install
        {--no-browser : Only publish the config, without installing Playwright}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish the config and install Playwright and its browser for recording';

    /**
     * The Playwright version the recorder is tested with.
     */
    protected const PLAYWRIGHT = '1.64.0';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'video-demo-config']);

        if ($this->option('no-browser')) {
            return self::SUCCESS;
        }

        $modules = config('video-demo.recorder.node_modules');
        $dir = dirname($modules);
        File::ensureDirectoryExists($dir);
        if (! File::exists("$dir/package.json")) {
            File::put("$dir/package.json", json_encode(['private' => true, 'name' => 'video-demo-recorder'], JSON_PRETTY_PRINT));
        }

        $this->components->task('Installing Playwright '.self::PLAYWRIGHT, fn () => $this->runInDir(
            [config('video-demo.recorder.npm'), 'install', '--no-audit', '--no-fund', 'playwright@'.self::PLAYWRIGHT],
            $dir,
        ));

        $this->components->task('Installing Chromium for Playwright', fn () => $this->runInDir(
            [config('video-demo.recorder.node'), "$modules/playwright/cli.js", 'install', 'chromium'],
            $dir,
        ));

        try {
            $hasFfmpeg = Process::run([config('video-demo.recorder.ffmpeg'), '-version'])->successful();
        } catch (\Throwable) {
            $hasFfmpeg = false;
        }
        if (! $hasFfmpeg) {
            $this->components->warn('ffmpeg could not be run. Install it (brew install ffmpeg, apt install ffmpeg), or set VIDEO_DEMO_FFMPEG to the full path of a copy, or of a wrapper script for a bundled copy (see the README).');
        }

        $this->newLine();
        $this->components->info('Next: run php artisan migrate, set ANTHROPIC_API_KEY and VIDEO_DEMO_LOGIN_AS in .env, start a queue worker, and open /'.trim(config('video-demo.studio.prefix'), '/'));
        $this->line('  To record videos on sample data instead of your own, build the sample database once: php artisan video-demo:sample-database');

        return self::SUCCESS;
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
