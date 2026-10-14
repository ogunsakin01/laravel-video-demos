<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Ogunsakin01\VideoDemo\Ai\VideoStopped;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use RuntimeException;

/** Runs the Node scripts in resources/recorder with Playwright */
class Recorder
{
    /**
     * Records the plan's steps for one version into $dir (an absolute folder).
     *
     * $recordAs is who to sign in as: a user id or email, "guest" for signed
     * out, or null for video-demo.recorder.login_as. $shouldStop is asked
     * every second; when it says yes the browser is closed.
     */
    public function record(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null): RecordingResult
    {
        $screen = Devices::get($device);
        $plan = $plan->forDevice($device);

        File::ensureDirectoryExists($dir);
        File::delete(File::glob("$dir/*.webm"));

        File::put("$dir/plan.json", json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put("$dir/job.json", json_encode([
            ...$this->browserSettings($screen, $recordAs),
            'planPath' => "$dir/plan.json",
            'outDir' => $dir,
            'speed' => static::speed(),
            'style' => [...VideoStyle::defaults(), ...array_intersect_key($style, array_flip(['theme', 'accent', 'captions', 'pace', 'art']))],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::delete("$dir/result.json");

        $this->run(static::script('record.cjs'), "$dir/job.json", static::timeoutFor($plan), $shouldStop);

        if (! File::exists("$dir/result.json")) {
            throw new RuntimeException('The recorder stopped without a result. Check that Node and Playwright work: php artisan video-demo:install');
        }

        $result = json_decode(File::get("$dir/result.json"), true) ?: [];

        // Lengths and chapter times as they will be in the sped-up video
        $speed = static::speed();
        $result['durationMs'] = ($result['durationMs'] ?? 0) / $speed;
        $result['chapters'] = array_map(fn ($c) => [...$c, 'at' => round($c['at'] / $speed, 2)], $result['chapters'] ?? []);

        return RecordingResult::fromArray($result, $device);
    }

    /**
     * Opens each path as the given user and reports the ones that don't work:
     * an error status, a redirect to sign-in, a JavaScript error, or a page
     * that doesn't load. Costs no AI; run before planning and recording.
     *
     * @param  list<mixed>  $paths
     * @return list<array{path: string, problem: string, status: ?int, url: ?string, detail: ?string}>
     */
    public function checkPages(array $paths, ?string $recordAs = null, string $device = 'desktop', ?Closure $shouldStop = null): array
    {
        $paths = array_values(array_unique(array_filter($paths, fn ($p) => is_string($p) && str_starts_with($p, '/'))));
        if ($paths === []) {
            return [];
        }

        $dir = static::workDir(trim(config('video-demo.storage.work_path'), '/').'/checks/'.uniqid());
        File::ensureDirectoryExists($dir);
        File::put("$dir/job.json", json_encode([
            ...$this->browserSettings(Devices::get($device), $recordAs),
            'paths' => $paths,
            'outDir' => $dir,
        ], JSON_UNESCAPED_SLASHES));

        try {
            $this->run(static::script('check.cjs'), "$dir/job.json", 60 + count($paths) * 20, $shouldStop);
            $result = File::exists("$dir/result.json") ? json_decode(File::get("$dir/result.json"), true) : null;
        } finally {
            File::deleteDirectory($dir);
        }

        if (! is_array($result)) {
            throw new RuntimeException('The page check stopped without a result. Check that Node and Playwright work: php artisan video-demo:install');
        }

        return $result['problems'] ?? [];
    }

    /** How many times faster the finished video plays than it was recorded */
    public static function speed(): float
    {
        return max(0.25, min(4.0, (float) config('video-demo.recorder.speed', 2)));
    }

    public static function script(string $name = 'record.cjs'): string
    {
        return realpath(__DIR__.'/../../resources/recorder/'.$name);
    }

    /**
     * Enough time for a plan: its length while recording (captions are held
     * longer at higher speeds) twice over, and never less than the config's
     */
    public static function timeoutFor(Plan $plan): int
    {
        $recording = $plan->estimatedSeconds(static::speed()) * static::speed();

        return (int) max(config('video-demo.recorder.timeout', 600), ceil($recording * 2 + 120));
    }

    protected function browserSettings(array $screen, ?string $recordAs): array
    {
        return [
            'baseUrl' => config('video-demo.recorder.base_url'),
            'loginPath' => $this->loginPath($recordAs),
            'viewport' => ['width' => $screen['width'], 'height' => $screen['height']],
            'touch' => $screen['touch'],
            'channel' => config('video-demo.recorder.channel'),
            'ignoreHttpsErrors' => (bool) config('video-demo.recorder.ignore_https_errors'),
        ];
    }

    /** Runs a Node script, stopping it when $shouldStop says so or when it runs too long */
    protected function run(string $script, string $job, int $timeout, ?Closure $shouldStop = null): void
    {
        $process = Process::timeout($timeout)
            ->env(['NODE_PATH' => implode(PATH_SEPARATOR, $this->nodePaths())])
            ->start([config('video-demo.recorder.node'), $script, $job]);

        while ($process->running()) {
            if ($shouldStop && $shouldStop()) {
                $process->signal(SIGTERM);
                usleep(500_000);
                if ($process->running()) {
                    $process->signal(SIGKILL);
                }

                throw new VideoStopped('Stopped by you.');
            }
            $process->ensureNotTimedOut();
            usleep(500_000);
        }

        $result = $process->wait();
        $output = trim($result->errorOutput() ?: $result->output());
        if (str_contains($output, "Cannot find module 'playwright'")) {
            throw new RuntimeException('Playwright is not installed. Run php artisan video-demo:install.');
        }
    }

    /** Folders Node searches for Playwright: the package's own install, then the app's */
    protected function nodePaths(): array
    {
        return array_values(array_filter([
            config('video-demo.recorder.node_modules'),
            base_path('node_modules'),
        ], 'is_dir'));
    }

    /** A signed, short-lived link that signs the recorder in, or null to stay signed out */
    protected function loginPath(?string $recordAs): ?string
    {
        $user = RecordAs::resolve($recordAs);

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

    /** The default user from video-demo.recorder.login_as, if set */
    public static function loginUser(): ?Authenticatable
    {
        $loginAs = config('video-demo.recorder.login_as');

        if ($loginAs === null || $loginAs === '') {
            return null;
        }

        try {
            return RecordAs::find((string) $loginAs);
        } catch (RuntimeException) {
            throw new RuntimeException("The recorder's user [$loginAs] was not found. Check video-demo.recorder.login_as.");
        }
    }

    /** The absolute path of a folder on the work disk, which must be a local disk */
    public static function workDir(string $path): string
    {
        $name = config('video-demo.storage.work_disk');

        // Every disk has path(), but only a local one gives a folder Node and ffmpeg can write to
        if (config("filesystems.disks.$name.driver") !== 'local') {
            throw new RuntimeException("video-demo.storage.work_disk must be a local disk; [$name] is not.");
        }

        return Storage::disk($name)->path($path);
    }
}
