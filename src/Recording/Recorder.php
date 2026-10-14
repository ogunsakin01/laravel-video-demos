<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use RuntimeException;

/** Runs resources/recorder/record.cjs with Node and Playwright */
class Recorder
{
    /** Records the plan's steps for one version into $dir (an absolute folder) */
    public function record(Plan $plan, string $dir, array $style = [], string $device = 'desktop'): RecordingResult
    {
        $screen = Devices::get($device);
        $plan = $plan->forDevice($device);

        File::ensureDirectoryExists($dir);
        File::delete(File::glob("$dir/*.webm"));

        File::put("$dir/plan.json", json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put("$dir/job.json", json_encode([
            'planPath' => "$dir/plan.json",
            'outDir' => $dir,
            'baseUrl' => config('video-demo.recorder.base_url'),
            'loginPath' => $this->loginPath(),
            'viewport' => ['width' => $screen['width'], 'height' => $screen['height']],
            'touch' => $screen['touch'],
            'channel' => config('video-demo.recorder.channel'),
            'ignoreHttpsErrors' => (bool) config('video-demo.recorder.ignore_https_errors'),
            'style' => [...VideoStyle::defaults(), ...array_intersect_key($style, array_flip(['theme', 'accent', 'captions', 'pace']))],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::delete("$dir/result.json");

        $process = Process::timeout((int) config('video-demo.recorder.timeout', 600))
            ->env(['NODE_PATH' => implode(PATH_SEPARATOR, $this->nodePaths())])
            ->run([config('video-demo.recorder.node'), static::script(), "$dir/job.json"]);

        if (! File::exists("$dir/result.json")) {
            $output = trim($process->errorOutput() ?: $process->output());

            throw new RuntimeException(str_contains($output, "Cannot find module 'playwright'")
                ? 'Playwright is not installed. Run php artisan video-demo:install.'
                : 'The recorder stopped without a result: '.str($output)->limit(600));
        }

        return RecordingResult::fromArray(json_decode(File::get("$dir/result.json"), true) ?: [], $device);
    }

    public static function script(): string
    {
        return realpath(__DIR__.'/../../resources/recorder/record.cjs');
    }

    /** Folders Node searches for Playwright: the package's own install, then the app's */
    protected function nodePaths(): array
    {
        return array_values(array_filter([
            config('video-demo.recorder.node_modules'),
            base_path('node_modules'),
        ], 'is_dir'));
    }

    /** A signed, short-lived link that signs the recorder in as the configured user */
    protected function loginPath(): ?string
    {
        $user = $this->loginUser();

        if (! $user) {
            return null;
        }

        return URL::temporarySignedRoute(
            'video-demo.studio.recorder-login',
            now()->addMinutes(10),
            ['user' => $user->getAuthIdentifier()],
            absolute: false,
        );
    }

    public static function loginUser(): ?Authenticatable
    {
        $loginAs = config('video-demo.recorder.login_as');

        if ($loginAs === null || $loginAs === '') {
            return null;
        }

        $provider = Auth::guard(config('video-demo.recorder.guard'))->getProvider();
        $user = is_numeric($loginAs)
            ? $provider->retrieveById($loginAs)
            : $provider->retrieveByCredentials(['email' => $loginAs]);

        if (! $user) {
            throw new RuntimeException("The recorder's user [$loginAs] was not found. Check video-demo.recorder.login_as.");
        }

        return $user;
    }

    /** The absolute path of a folder on the work disk, which must be a local disk */
    public static function workDir(string $path): string
    {
        $disk = Storage::disk(config('video-demo.storage.work_disk'));

        if (! method_exists($disk, 'path')) {
            throw new RuntimeException('video-demo.storage.work_disk must be a local disk.');
        }

        return $disk->path($path);
    }
}
