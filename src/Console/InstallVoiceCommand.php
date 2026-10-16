<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Voice\Voices;

/**
 * Installs the local voice (Kokoro) that narrates videos for free on this
 * machine: its Node package next to the recorder's Playwright, and its model
 * in the user's cache folder, outside every project.
 */
class InstallVoiceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:install-voice';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the free local voice that narrates videos on this machine (about 500 MB, once)';

    /**
     * The kokoro-js version the package is tested with.
     */
    public const KOKORO = '1.2.1';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modules = (string) config('video-demo.recorder.node_modules');
        $dir = dirname($modules);
        File::ensureDirectoryExists($dir);
        if (! File::exists("$dir/package.json")) {
            File::put("$dir/package.json", json_encode(['private' => true, 'name' => 'video-demo-recorder'], JSON_PRETTY_PRINT));
        }

        $installed = false;
        $this->components->task('Installing the local voice (Kokoro '.self::KOKORO.')', function () use ($dir, &$installed) {
            $result = Process::path($dir)->timeout(900)->run([config('video-demo.recorder.npm', 'npm'), 'install', '--no-audit', '--no-fund', 'kokoro-js@'.self::KOKORO]);
            if ($result->failed()) {
                $this->components->error(trim($result->errorOutput()) ?: trim($result->output()));
            }

            return $installed = $result->successful();
        });
        if (! $installed) {
            return self::FAILURE;
        }

        $this->components->task('Removing the copies for other systems', fn () => $this->prune($modules));

        $voices = null;
        $this->components->task('Downloading the voice model (about 330 MB, once)', function () use (&$voices) {
            $dir = Recorder::workDir(trim((string) config('video-demo.storage.work_path'), '/').'/voice/warmup-'.uniqid());
            File::ensureDirectoryExists($dir);
            try {
                File::put("$dir/job.json", json_encode(['warmup' => true, 'cacheDir' => Voices::modelCache()], JSON_UNESCAPED_SLASHES));
                $result = Process::timeout(900)->env(['NODE_PATH' => (string) config('video-demo.recorder.node_modules')])
                    ->run([config('video-demo.recorder.node', 'node'), Recorder::script('voice.cjs'), "$dir/job.json"]);
                $status = is_file("$dir/result.json") ? json_decode((string) File::get("$dir/result.json"), true) : null;
                if (! ($status['ok'] ?? false)) {
                    $this->components->error($status['error'] ?? (trim($result->errorOutput()) ?: 'The model could not be downloaded.'));

                    return false;
                }
                $voices = $status['voices'] ?? [];

                return true;
            } finally {
                File::deleteDirectory($dir);
            }
        });
        if ($voices === null) {
            return self::FAILURE;
        }

        $this->components->info(count($voices).' local voices are ready. Choose one under Voice-over when you make a video. The model is kept in '.Voices::modelCache().'.');

        return self::SUCCESS;
    }

    /**
     * Remove what this machine doesn't need: the speech engine built for other
     * systems and processors, and the browser build Node doesn't use.
     */
    protected function prune(string $modules): bool
    {
        $platform = ['Darwin' => 'darwin', 'Windows' => 'win32', 'Linux' => 'linux'][PHP_OS_FAMILY] ?? null;
        $arch = in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true) ? 'arm64' : 'x64';

        foreach (File::glob("$modules/onnxruntime-node/bin/napi-v*/*") as $dir) {
            if ($platform && basename($dir) !== $platform) {
                File::deleteDirectory($dir);
            }
        }
        foreach (File::glob("$modules/onnxruntime-node/bin/napi-v*/*/*") as $dir) {
            if (is_dir($dir) && basename($dir) !== $arch) {
                File::deleteDirectory($dir);
            }
        }
        File::deleteDirectory("$modules/onnxruntime-web");

        return true;
    }
}
