<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Ogunsakin01\VideoDemo\Voice\Voices;
use Throwable;

/**
 * Frees disk space: leftover working files and sample databases, and
 * optionally the recorder's browser. Finished videos and the library are never touched.
 */
class CleanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:clean
        {--browser : Also remove the Playwright install and its browser (video-demo:install puts them back)}
        {--voice : Also remove the local voice, its model and the saved voice-over clips (video-demo:install-voice puts the voice back)}
        {--dry-run : List what would be removed, without removing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove leftover working files, and optionally the recorder\'s browser';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $remove = $this->leftovers();

        if ($this->option('browser')) {
            $modules = (string) config('video-demo.recorder.node_modules');
            if ($this->insideStorage($modules)) {
                $remove[] = ['path' => dirname(realpath($modules)), 'why' => 'the Playwright install and its browser'];
            } elseif ($modules !== '') {
                $this->components->warn("Playwright at [$modules] is outside this app's storage folder, so it was left alone.");
            }
        }

        if ($this->option('voice')) {
            $modules = (string) config('video-demo.recorder.node_modules');
            foreach (['kokoro-js', '@huggingface', 'onnxruntime-node', 'onnxruntime-common', 'onnxruntime-web', 'phonemizer'] as $package) {
                if (is_dir("$modules/$package")) {
                    $remove[] = ['path' => "$modules/$package", 'why' => "the local voice ($package)"];
                }
            }
            if (is_dir(Voices::modelCache())) {
                $remove[] = ['path' => Voices::modelCache(), 'why' => 'the local voice model'];
            }
            $clips = Recorder::workDir(trim((string) config('video-demo.storage.work_path'), '/').'/voice');
            if (is_dir($clips)) {
                $remove[] = ['path' => $clips, 'why' => 'saved voice-over clips'];
            }
        }

        if ($remove === []) {
            $this->components->info('Nothing to clean up.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($remove as $item) {
            $size = $this->size($item['path']);
            $total += $size;
            $this->components->twoColumnDetail($item['why'], $this->human($size));
        }

        if ($this->option('dry-run')) {
            $this->components->info('Would free '.$this->human($total).'. Run without --dry-run to remove it.');

            return self::SUCCESS;
        }

        foreach ($remove as $item) {
            if ($item['why'] === 'the Playwright install and its browser') {
                $this->uninstallBrowser((string) config('video-demo.recorder.node_modules'));
            }
            is_dir($item['path']) ? File::deleteDirectory($item['path']) : File::delete($item['path']);
        }

        $this->components->info('Freed '.$this->human($total).'.');

        return self::SUCCESS;
    }

    /**
     * Determine if a Playwright install (its node_modules folder) is inside a
     * folder of its own in this app's storage, so removing that folder can't
     * take storage itself, or anything outside it, with it.
     */
    protected function insideStorage(string $modules): bool
    {
        $modules = $modules !== '' ? realpath($modules) : false;
        $storage = realpath(storage_path());
        if ($modules === false || $storage === false || basename($modules) !== 'node_modules') {
            return false;
        }

        return str_starts_with(dirname($modules), $storage.DIRECTORY_SEPARATOR);
    }

    /**
     * Find working files nothing needs: temporary folders, the folders of
     * deleted videos, and raw recordings left by runs that stopped early.
     *
     * @return list<array{path: string, why: string}>
     */
    protected function leftovers(): array
    {
        try {
            $root = Recorder::workDir(trim((string) config('video-demo.storage.work_path'), '/'));
        } catch (Throwable) {
            return [];
        }
        if (! is_dir($root)) {
            return [];
        }

        $videos = Video::query()->get(['id', 'status', 'verify_result', 'spots', 'exports'])->keyBy('id');
        $remove = [];

        foreach (File::directories($root) as $dir) {
            $name = basename($dir);

            // Sample databases are in use while anything runs for their video:
            // making it, checking it, or finding spots.
            if ($name === 'sandbox') {
                $current = 'base-'.Sandbox::fingerprint().'.sqlite';
                foreach (File::files($dir) as $file) {
                    $name = $file->getFilename();
                    // The current base database is kept: every video's copy comes from it.
                    if (str_starts_with($name, 'base')) {
                        if (! str_starts_with($name, $current) && $name !== 'base.lock') {
                            $remove[] = ['path' => $file->getPathname(), 'why' => 'an out-of-date sample database'];
                        }

                        continue;
                    }
                    $id = (int) strtok($name, '-');
                    if (! $videos->get($id)?->isBusy()) {
                        $remove[] = ['path' => $file->getPathname(), 'why' => 'sample database of video #'.$id];
                    }
                }

                continue;
            }

            if (in_array($name, ['checks', 'camera', 'mix'], true)) {
                $remove[] = ['path' => $dir, 'why' => "temporary files ($name)"];

                continue;
            }

            if (! ctype_digit($name)) {
                continue;
            }

            $video = $videos->get((int) $name);
            if (! $video) {
                $remove[] = ['path' => $dir, 'why' => "files of deleted video #$name"];

                continue;
            }

            // Raw browser recordings and working folders are only needed while
            // something runs. Copies (exports) and spot pictures (spots) are the
            // video's own files and stay.
            if (! $video->isBusy()) {
                foreach (File::directories($dir) as $raw) {
                    $folder = basename($raw);
                    if ($folder === 'exports') {
                        foreach (File::directories($raw) as $work) {
                            $remove[] = ['path' => $work, 'why' => "temporary files of video #$name (exports/".basename($work).')'];
                        }
                    } elseif ($folder !== 'spots') {
                        $remove[] = ['path' => $raw, 'why' => "raw recording of video #$name ($folder)"];
                    }
                }
            }
        }

        return $remove;
    }

    /**
     * Remove the browser Playwright downloaded, before its install is deleted.
     */
    protected function uninstallBrowser(string $modules): void
    {
        $cli = "$modules/playwright/cli.js";
        if (! is_file($cli)) {
            return;
        }

        Process::timeout(300)->run([config('video-demo.recorder.node', 'node'), $cli, 'uninstall']);
    }

    /**
     * Get the size of a file or folder, in bytes.
     */
    protected function size(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }

        return (int) collect(File::allFiles($path, true))->sum(fn ($f) => $f->getSize());
    }

    /**
     * Format a size for people.
     */
    protected function human(int $bytes): string
    {
        foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $unit => $size) {
            if ($bytes >= $size) {
                return round($bytes / $size, 1)." $unit";
            }
        }

        return "$bytes B";
    }
}
