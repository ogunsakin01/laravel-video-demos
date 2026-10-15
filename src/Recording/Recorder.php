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
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use RuntimeException;

/**
 * Runs the Node scripts in resources/recorder with Playwright.
 */
class Recorder
{
    /**
     * The sample database the browser uses, when the video is recorded on sample data.
     */
    public ?Sandbox $sandbox = null;

    /**
     * The language of each page the last check opened, path => its lang
     * attribute, such as "fr" or "pt-BR".
     *
     * @var array<string, string>
     */
    public array $pageLanguages = [];

    /**
     * Record the plan's steps for one version into $dir (an absolute folder).
     *
     * $recordAs is who to sign in as: a user id or email, "guest" for signed
     * out, or null for video-demo.recorder.login_as. $shouldStop is asked
     * twice a second; when it returns true the browser is closed. $speed is
     * how much faster the video will play, or null for the configured speed.
     * $voice gives each spoken line's length in seconds, keyed by the line,
     * so the step speaking it is held until it is finished.
     *
     * @param  array<string, float>  $voice
     */
    public function record(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null, ?float $speed = null, array $voice = []): RecordingResult
    {
        $speed = static::speed($speed);
        $screen = Devices::get($device);
        $plan = $plan->forDevice($device);

        if ($voice !== []) {
            $plan = $plan->withHolds($voice, $speed);
        }

        File::ensureDirectoryExists($dir);
        File::delete(File::glob("$dir/*.webm"));

        File::put("$dir/plan.json", json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put("$dir/job.json", json_encode([
            ...$this->browserSettings($screen, $recordAs),
            'planPath' => "$dir/plan.json",
            'outDir' => $dir,
            'speed' => $speed,
            'style' => [...VideoStyle::defaults(), ...array_intersect_key($style, array_flip(['theme', 'accent', 'captions', 'pace', 'art', 'app_theme', 'zoom', 'highlight']))],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::delete("$dir/result.json");

        $this->run(static::script('record.cjs'), "$dir/job.json", static::timeoutFor($plan, $speed), $shouldStop);

        if (! File::exists("$dir/result.json")) {
            throw new RuntimeException('The recorder stopped without a result. Check that Node and Playwright work: php artisan video-demo:install');
        }

        $result = json_decode(File::get("$dir/result.json"), true) ?: [];

        // Lengths and times as they will be in the finished video: played at its
        // speed, with the waits on the app cut out.
        $timeline = Timeline::build((float) ($result['durationMs'] ?? 0), $speed, $result['idle'] ?? [], $result['speaking'] ?? []);
        $result['timeline'] = $timeline->toArray();
        $result['durationMs'] = $timeline->seconds() * 1000;
        $result['chapters'] = array_map(fn ($c) => [...$c, 'at' => round($timeline->at($c['at'] * 1000), 2)], $result['chapters'] ?? []);
        $result['focus'] = array_map(fn ($f) => [...$f, 't' => $timeline->at($f['t'])], $result['focus'] ?? []);
        $result['starts'] = array_map(fn ($t) => $timeline->at($t), $result['starts'] ?? []);
        $result['lines'] = array_map(fn ($step) => $step['say'] ?? null, $plan->steps);

        return RecordingResult::fromArray($result, $device);
    }

    /**
     * Open each path as the given user and report the ones that don't work.
     *
     * A page fails on an error status, a redirect to sign-in, a JavaScript
     * error, or not loading. It costs no AI, so it runs before planning and
     * recording. The language of each page is kept in $pageLanguages.
     *
     * @param  list<mixed>  $paths
     * @return list<array{path: string, problem: string, status: ?int, url: ?string, detail: ?string}>
     */
    public function checkPages(array $paths, ?string $recordAs = null, string $device = 'desktop', ?Closure $shouldStop = null): array
    {
        $paths = array_values(array_unique(array_filter($paths, fn ($p) => is_string($p) && str_starts_with($p, '/'))));
        $this->pageLanguages = [];
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
        $this->pageLanguages = array_filter((array) ($result['languages'] ?? []), 'is_string');

        return $result['problems'] ?? [];
    }

    /**
     * Find which of these paths are pages the given user can open.
     *
     * A page counts when it returns 200 with HTML and doesn't redirect. Each
     * comes back with the page's heading or title.
     *
     * @param  list<string>  $paths
     * @return array{pages: list<array{path: string, title: string}>, skipped: list<array{path: string, reason: string}>}
     */
    public function scanPages(array $paths, ?string $recordAs = null): array
    {
        $dir = static::workDir(trim(config('video-demo.storage.work_path'), '/').'/checks/'.uniqid('pages-'));
        File::ensureDirectoryExists($dir);
        File::put("$dir/job.json", json_encode([
            ...$this->browserSettings(Devices::get('desktop'), $recordAs),
            'paths' => $paths,
            'outDir' => $dir,
        ], JSON_UNESCAPED_SLASHES));

        try {
            $this->run(static::script('pages.cjs'), "$dir/job.json", 60 + count($paths) * 5);
            $result = File::exists("$dir/result.json") ? json_decode(File::get("$dir/result.json"), true) : null;
        } finally {
            File::deleteDirectory($dir);
        }

        if (! is_array($result)) {
            throw new RuntimeException('The page scan stopped without a result. Check that Node and Playwright work: php artisan video-demo:install');
        }

        return ['pages' => $result['pages'] ?? [], 'skipped' => $result['skipped'] ?? []];
    }

    /**
     * List the sections of each page a video could be shown in, as the given
     * user sees them on a desktop screen.
     *
     * @param  list<string>  $paths
     * @return array{pages: list<array{path: string, title: string, sections: list<array{id: int, selector: string, kind: string, heading: string, text: string, box: array{x: int, y: int, w: int, h: int}}>}>, failed: list<array{path: string, reason: string}>}
     */
    public function mapPages(array $paths, ?string $recordAs = null): array
    {
        $result = $this->runSpots(['mode' => 'map', 'paths' => $paths, ...$this->browserSettings(Devices::get('desktop'), $recordAs)], 60 + count($paths) * 30);

        return ['pages' => $result['pages'] ?? [], 'failed' => $result['failed'] ?? []];
    }

    /**
     * Show a player card for the video in each spot, and take a screenshot of
     * it on each screen, saved in $outDir as <spot number>-<screen>.jpg.
     *
     * @param  list<array{path: string, selector: string, position: string}>  $spots
     * @param  array{poster: ?string, title: string, length: string, accent?: string}  $card
     * @param  list<string>  $screens  Device keys, such as desktop and mobile.
     * @return array{shots: list<array{spot: int, screen: string, file: string}>, missing: list<array{spot: int, screen: string, reason: string}>}
     */
    public function shootSpots(array $spots, array $card, string $outDir, array $screens = ['desktop', 'mobile'], ?string $recordAs = null): array
    {
        File::ensureDirectoryExists($outDir);
        $result = $this->runSpots([
            'mode' => 'shoot', 'spots' => $spots, ...$card, 'outDir' => $outDir,
            ...$this->browserSettings(Devices::get('desktop'), $recordAs),
            'screens' => array_map(fn ($key) => ['key' => $key, 'viewport' => ['width' => Devices::get($key)['width'], 'height' => Devices::get($key)['height']], 'touch' => Devices::get($key)['touch']], $screens),
        ], 60 + count($spots) * count($screens) * 25);

        return ['shots' => $result['shots'] ?? [], 'missing' => $result['missing'] ?? []];
    }

    /**
     * Run spots.cjs with the given job and get its result. The result is
     * written to a temporary folder; screenshots go to the job's outDir.
     */
    protected function runSpots(array $job, int $timeout): array
    {
        $dir = static::workDir(trim(config('video-demo.storage.work_path'), '/').'/checks/'.uniqid('spots-'));
        File::ensureDirectoryExists($dir);
        File::put("$dir/job.json", json_encode(['outDir' => $dir, ...$job, 'resultDir' => $dir], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            $this->run(static::script('spots.cjs'), "$dir/job.json", $timeout);
            $result = File::exists("$dir/result.json") ? json_decode(File::get("$dir/result.json"), true) : null;
        } finally {
            File::deleteDirectory($dir);
        }

        if (! is_array($result)) {
            throw new RuntimeException('Finding spots for the video stopped without a result. Check that Node and Playwright work: php artisan video-demo:install');
        }
        if (! empty($result['error'])) {
            throw new RuntimeException('Finding spots for the video failed: '.$result['error']);
        }

        return $result;
    }

    /**
     * Zoom a finished MP4 in on each step, in place.
     *
     * The frames are drawn through a virtual camera by camera.cjs, between
     * ffmpeg splitting the video and joining it again. Returns false, leaving
     * the video as it was, when that doesn't work.
     *
     * @param  list<array{s: float, z: float, x: float, y: float}>  $keyframes
     */
    public function applyCamera(string $mp4, array $keyframes, int $width, int $height, Encoder $encoder): bool
    {
        if (count($keyframes) < 2) {
            return false;
        }

        $dir = static::workDir(trim(config('video-demo.storage.work_path'), '/').'/camera/'.uniqid());
        try {
            $encoder->frames($mp4, "$dir/in");
            File::put("$dir/job.json", json_encode([
                'framesDir' => "$dir/in", 'outDir' => "$dir/out", 'width' => $width, 'height' => $height,
                'fps' => Encoder::FPS, 'move' => Camera::MOVE, 'keys' => $keyframes, 'channel' => config('video-demo.recorder.channel'),
            ], JSON_UNESCAPED_SLASHES));
            $this->run(static::script('camera.cjs'), "$dir/job.json", 600);
            if (! File::exists("$dir/out/done.json")) {
                return false;
            }
            $encoder->join("$dir/out", "$dir/zoomed.mp4");
            File::move("$dir/zoomed.mp4", $mp4);

            return true;
        } catch (RuntimeException $e) {
            report($e);

            return false;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Get how many times faster the finished video plays than it was recorded.
     *
     * A video's own speed wins over the configured one. Either is kept
     * between 0.25 and 4.
     */
    public static function speed(?float $speed = null): float
    {
        return max(0.25, min(4.0, $speed ?: (float) config('video-demo.recorder.speed', 1)));
    }

    /**
     * Get the absolute path of a script in resources/recorder.
     */
    public static function script(string $name = 'record.cjs'): string
    {
        return realpath(__DIR__.'/../../resources/recorder/'.$name);
    }

    /**
     * Get the time limit for recording a plan, in seconds.
     *
     * It allows twice the plan's length while recording (captions are held
     * longer at higher speeds), and never less than the configured timeout.
     */
    public static function timeoutFor(Plan $plan, ?float $speed = null): int
    {
        $speed = static::speed($speed);
        $recording = $plan->estimatedSeconds($speed) * $speed;

        return (int) max(config('video-demo.recorder.timeout', 600), ceil($recording * 2 + 120));
    }

    /**
     * Get the browser settings every recorder script shares.
     */
    protected function browserSettings(array $screen, ?string $recordAs): array
    {
        return [
            'baseUrl' => config('video-demo.recorder.base_url'),
            'loginPath' => $this->loginPath($recordAs),
            'viewport' => ['width' => $screen['width'], 'height' => $screen['height']],
            'touch' => $screen['touch'],
            'channel' => config('video-demo.recorder.channel'),
            'ignoreHttpsErrors' => (bool) config('video-demo.recorder.ignore_https_errors'),
            // Sends the browser's requests to the sample database.
            'cookies' => $this->sandbox ? [[...$this->sandbox->cookie(), 'url' => rtrim((string) config('video-demo.recorder.base_url'), '/').'/']] : [],
        ];
    }

    /**
     * Run a Node script, stopping it when $shouldStop says so or when it runs too long.
     */
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

    /**
     * Get the folders Node searches for Playwright: the package's own install,
     * then the app's.
     */
    protected function nodePaths(): array
    {
        return array_values(array_filter([
            config('video-demo.recorder.node_modules'),
            base_path('node_modules'),
        ], 'is_dir'));
    }

    /**
     * Get a signed, short-lived link that signs the recorder in, or null to stay
     * signed out.
     */
    protected function loginPath(?string $recordAs): ?string
    {
        // On sample data, the user is the one made in the sample database.
        $id = $this->sandbox ? $this->sandbox->userId() : RecordAs::resolve($recordAs)?->getAuthIdentifier();

        if ($id === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'video-demo.studio.recorder-login',
            now()->addMinutes(10),
            ['user' => $id],
            absolute: false,
        );
    }

    /**
     * Get the default user from video-demo.recorder.login_as, if one is set.
     */
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

    /**
     * Get the absolute path of a folder on the work disk, which must be a local disk.
     */
    public static function workDir(string $path): string
    {
        $name = config('video-demo.storage.work_disk');

        // Every disk has path(), but only a local one gives a folder Node and ffmpeg can write to.
        if (config("filesystems.disks.$name.driver") !== 'local') {
            throw new RuntimeException("video-demo.storage.work_disk must be a local disk; [$name] is not.");
        }

        return Storage::disk($name)->path($path);
    }
}
