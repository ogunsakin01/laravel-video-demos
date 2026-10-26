<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Ai\VideoStopped;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use RuntimeException;
use Throwable;

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
     * The cookie that asks the app to show its pages in a language.
     */
    public const LOCALE_COOKIE = 'video_demo_locale';

    /**
     * The language the app is opened in, such as "fr", for a video made in
     * another language than the one it was planned in. Null leaves the app
     * in its own language.
     */
    public ?string $locale = null;

    /**
     * The errors the app reported on the copy of the sample database of the
     * version that failed, from the last recordVersions() or verifyVersions().
     *
     * @var list<array<string, mixed>>
     */
    public array $versionErrors = [];

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
        $job = $this->prepareRecording($plan, $dir, $style, $device, $recordAs, $speed, $voice);

        return ($job['read'])($this->run($job['script'], $job['job'], $job['timeout'], $shouldStop));
    }

    /**
     * Write the job that records one version, ready to run, with what reads its result.
     *
     * @param  array<string, float>  $voice
     * @return array{script: string, job: string, timeout: int, read: Closure(string): RecordingResult}
     */
    protected function prepareRecording(Plan $plan, string $dir, array $style, string $device, ?string $recordAs, ?float $speed, array $voice): array
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
            'signInAs' => $this->signInPaths($plan),
            'planPath' => "$dir/plan.json",
            'outDir' => $dir,
            'speed' => $speed,
            'style' => [
                ...VideoStyle::defaults(),
                ...array_intersect_key($style, array_flip(['theme', 'accent', 'captions', 'pace', 'art', 'app_theme', 'zoom', 'highlight', 'logo_position'])),
                'logo_src' => VideoStyle::logoSource($style),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::delete("$dir/result.json");

        return [
            'script' => static::script('record.cjs'),
            'job' => "$dir/job.json",
            'timeout' => static::timeoutFor($plan, $speed),
            'read' => fn (string $output) => $this->readRecording($plan, $dir, $device, $speed, $output),
        ];
    }

    /**
     * Read what recording one version made.
     */
    protected function readRecording(Plan $plan, string $dir, string $device, float $speed, string $output): RecordingResult
    {
        if (! File::exists("$dir/result.json")) {
            throw static::noResult('The recorder', $output);
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
     * Play the plan for one version without recording it, as fast as it
     * goes, to check that every step still works after the app changed. No
     * cursor, captions or cards are shown and no video is made: only the
     * result, with a screenshot in $dir when a step fails. It costs no AI.
     */
    public function verify(Plan $plan, string $dir, array $style = [], string $device = 'desktop', ?string $recordAs = null, ?Closure $shouldStop = null): RecordingResult
    {
        $job = $this->prepareCheck($plan, $dir, $style, $device, $recordAs);

        return ($job['read'])($this->run($job['script'], $job['job'], $job['timeout'], $shouldStop));
    }

    /**
     * Write the job that plays one version without recording it, ready to run, with what reads its result.
     *
     * @return array{script: string, job: string, timeout: int, read: Closure(string): RecordingResult}
     */
    protected function prepareCheck(Plan $plan, string $dir, array $style, string $device, ?string $recordAs): array
    {
        $plan = $plan->forDevice($device);

        File::ensureDirectoryExists($dir);
        File::put("$dir/plan.json", json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put("$dir/job.json", json_encode([
            ...$this->browserSettings(Devices::get($device), $recordAs),
            'signInAs' => $this->signInPaths($plan),
            'planPath' => "$dir/plan.json",
            'outDir' => $dir,
            'dryRun' => true,
            // The app's colour scheme can change what is on the page; the rest of the look isn't shown.
            'style' => ['app_theme' => $style['app_theme'] ?? 'auto'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::delete("$dir/result.json");

        return [
            'script' => static::script('record.cjs'),
            'job' => "$dir/job.json",
            'timeout' => 120 + count($plan->steps) * 20,
            'read' => function (string $output) use ($dir, $device): RecordingResult {
                if (! File::exists("$dir/result.json")) {
                    throw static::noResult('The check', $output);
                }

                return RecordingResult::fromArray(json_decode(File::get("$dir/result.json"), true) ?: [], $device);
            },
        ];
    }

    /**
     * Record the plan on several versions (screens), each into its own
     * folder and on its own copy of the sample database (when $this->sandbox
     * is set), up to $parallel at once. The first that fails stops the
     * others, and is the last result; the app errors on its copy are kept in
     * $versionErrors. $started and $finished are told about each version.
     *
     * @param  array<string, string>  $dirs  device => folder
     * @param  array<string, float>  $voice
     * @param  ?Closure(string): void  $started
     * @param  ?Closure(string, RecordingResult): void  $finished
     * @return array<string, RecordingResult> device => result, in the order they finished
     */
    public function recordVersions(Plan $plan, array $dirs, array $style = [], ?string $recordAs = null, ?float $speed = null, array $voice = [], int $parallel = 1, ?Closure $shouldStop = null, ?Closure $started = null, ?Closure $finished = null): array
    {
        return $this->eachVersion(
            $dirs, $parallel, $shouldStop, $started, $finished,
            fn (string $device, string $dir) => $this->record($plan, $dir, $style, $device, $recordAs, $shouldStop, $speed, $voice),
            fn (string $device, string $dir) => $this->prepareRecording($plan, $dir, $style, $device, $recordAs, $speed, $voice),
        );
    }

    /**
     * Play the plan without recording it on several versions, the way
     * recordVersions() records them.
     *
     * @param  array<string, string>  $dirs  device => folder
     * @param  ?Closure(string): void  $started
     * @param  ?Closure(string, RecordingResult): void  $finished
     * @return array<string, RecordingResult> device => result, in the order they finished
     */
    public function verifyVersions(Plan $plan, array $dirs, array $style = [], ?string $recordAs = null, int $parallel = 1, ?Closure $shouldStop = null, ?Closure $started = null, ?Closure $finished = null): array
    {
        return $this->eachVersion(
            $dirs, $parallel, $shouldStop, $started, $finished,
            fn (string $device, string $dir) => $this->verify($plan, $dir, $style, $device, $recordAs, $shouldStop),
            fn (string $device, string $dir) => $this->prepareCheck($plan, $dir, $style, $device, $recordAs),
        );
    }

    /**
     * Get how many versions of a video to record at once:
     * video-demo.recorder.parallel_versions, no more than there are versions.
     */
    public static function parallelVersions(int $versions): int
    {
        return max(1, min((int) config('video-demo.recorder.parallel_versions', 1), $versions, 4));
    }

    /**
     * Run each version, one at a time ($one) or up to $parallel at once
     * (each $prepare'd, then run side by side), each on its own copy of the
     * sample database, stopping at the first that fails.
     *
     * @param  array<string, string>  $dirs
     * @param  Closure(string, string): RecordingResult  $one
     * @param  Closure(string, string): array{script: string, job: string, timeout: int, read: Closure(string): RecordingResult}  $prepare
     * @return array<string, RecordingResult>
     */
    protected function eachVersion(array $dirs, int $parallel, ?Closure $shouldStop, ?Closure $started, ?Closure $finished, Closure $one, Closure $prepare): array
    {
        $this->versionErrors = [];
        $base = $this->sandbox;
        $results = [];

        // A version's copy, used while its job is written (and, one at a time, run); dropped if that fails.
        $onCopy = function (Closure $use) use ($base) {
            $copy = $base?->copy();
            $this->sandbox = $copy;
            try {
                return [$use(), $copy];
            } catch (Throwable $e) {
                $copy?->delete();

                throw $e;
            } finally {
                $this->sandbox = $base;
            }
        };
        // Keep the app's errors when the version failed, then drop its copy.
        $done = function (string $device, RecordingResult $result, ?Sandbox $copy) use (&$results, $finished) {
            if (! $result->ok && $copy) {
                $this->versionErrors = $copy->appErrors();
            }
            $copy?->delete();
            $results[$device] = $result;
            if ($finished) {
                $finished($device, $result);
            }
        };

        if ($parallel <= 1 || count($dirs) <= 1) {
            foreach ($dirs as $device => $dir) {
                if ($shouldStop && $shouldStop()) {
                    throw new VideoStopped('Stopped by you.');
                }
                if ($started) {
                    $started($device);
                }
                [$result, $copy] = $onCopy(fn () => $one($device, $dir));
                $done($device, $result, $copy);
                if (! $result->ok) {
                    break;
                }
            }

            return $results;
        }

        $waiting = $dirs;
        /** @var array<string, array{process: InvokedProcess|FakeInvokedProcess, read: Closure(string): RecordingResult, copy: ?Sandbox}> $running */
        $running = [];
        try {
            while ($waiting !== [] || $running !== []) {
                while ($waiting !== [] && count($running) < $parallel) {
                    $device = (string) array_key_first($waiting);
                    $dir = array_shift($waiting);
                    if ($started) {
                        $started($device);
                    }
                    [$job, $copy] = $onCopy(fn () => $prepare($device, $dir));
                    $running[$device] = ['process' => $this->start($job['script'], $job['job'], $job['timeout']), 'read' => $job['read'], 'copy' => $copy];
                }

                if ($shouldStop && $shouldStop()) {
                    throw new VideoStopped('Stopped by you.');
                }

                foreach ($running as $device => $version) {
                    if ($version['process']->running()) {
                        $version['process']->ensureNotTimedOut();

                        continue;
                    }
                    unset($running[$device]);
                    $done($device, ($version['read'])($this->output($version['process']->wait())), $version['copy']);
                    if (! $results[$device]->ok) {
                        // The plan goes back to the AI, and every version is played again.
                        return $results;
                    }
                }

                if ($running !== []) {
                    usleep(500_000);
                }
            }

            return $results;
        } finally {
            // Stopped, failed or done: nothing is left running, and no copy is left behind.
            foreach ($running as $version) {
                $this->end($version['process']);
                $version['copy']?->delete();
            }
        }
    }

    /**
     * Open one page as the given user and describe what is on it (headings,
     * links, buttons, fields with their labels, text), for the AI model while
     * it plans. Nothing is recorded, and it costs no AI by itself.
     *
     * @return array{ok: bool, url: ?string, title: ?string, status: ?int, snapshot: ?string, error: ?string}
     */
    public function lookAtPage(string $path, ?string $recordAs = null, string $device = 'desktop', int $limit = 6000, ?Closure $shouldStop = null): array
    {
        $dir = static::workDir(trim(config('video-demo.storage.work_path'), '/').'/checks/'.uniqid('look-'));
        File::ensureDirectoryExists($dir);
        File::put("$dir/job.json", json_encode([
            ...$this->browserSettings(Devices::get($device), $recordAs),
            'path' => $path,
            'limit' => $limit,
            'outDir' => $dir,
        ], JSON_UNESCAPED_SLASHES));

        try {
            $output = $this->run(static::script('look.cjs'), "$dir/job.json", 90, $shouldStop);
            $result = File::exists("$dir/result.json") ? json_decode(File::get("$dir/result.json"), true) : null;
        } finally {
            File::deleteDirectory($dir);
        }

        if (! is_array($result)) {
            throw static::noResult('Looking at the page', $output);
        }

        return $result;
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
            $output = $this->run(static::script('check.cjs'), "$dir/job.json", 60 + count($paths) * 20, $shouldStop);
            $result = File::exists("$dir/result.json") ? json_decode(File::get("$dir/result.json"), true) : null;
        } finally {
            File::deleteDirectory($dir);
        }

        if (! is_array($result)) {
            throw static::noResult('The page check', $output);
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
            $output = $this->run(static::script('pages.cjs'), "$dir/job.json", 60 + count($paths) * 5);
            $result = File::exists("$dir/result.json") ? json_decode(File::get("$dir/result.json"), true) : null;
        } finally {
            File::deleteDirectory($dir);
        }

        if (! is_array($result)) {
            throw static::noResult('The page scan', $output);
        }
        if (! empty($result['error'])) {
            throw new RuntimeException('The page scan failed: '.$result['error']);
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
     * Put the video's player into each spot and check it doesn't upset the
     * page, minimised and open, on each screen; screenshots of each are saved
     * in $outDir as <spot number>-<screen>-<closed|open>.jpg. Each spot's
     * positions are tried in order until one passes everywhere.
     *
     * @param  list<array{path: string, selector: string, positions: list<string>}>  $spots
     * @param  string  $html  The player, as the app will render it.
     * @param  list<string>  $screens  Device keys, such as desktop and mobile.
     * @return list<array{found: bool, position: ?string, ok: bool, problems: list<array{screen: string, state: string, problem: string}>, shots: array<string, array{closed: string, open: string}>}>
     */
    public function shootSpots(array $spots, string $html, string $outDir, array $screens = ['desktop', 'mobile'], ?string $recordAs = null, string $accent = '#e5693d'): array
    {
        File::ensureDirectoryExists($outDir);
        $result = $this->runSpots([
            'mode' => 'shoot', 'spots' => $spots, 'html' => $html, 'accent' => $accent, 'outDir' => $outDir,
            ...$this->browserSettings(Devices::get('desktop'), $recordAs),
            'screens' => array_map(fn ($key) => ['key' => $key, 'viewport' => ['width' => Devices::get($key)['width'], 'height' => Devices::get($key)['height']], 'touch' => Devices::get($key)['touch']], $screens),
        ], 60 + count($spots) * count($screens) * 3 * 15);

        return $result['spots'] ?? [];
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
            $output = $this->run(static::script('spots.cjs'), "$dir/job.json", $timeout);
            $result = File::exists("$dir/result.json") ? json_decode(File::get("$dir/result.json"), true) : null;
        } finally {
            File::deleteDirectory($dir);
        }

        if (! is_array($result)) {
            throw static::noResult('Finding spots for the video', $output);
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
     * Turn numbered JPEG frames into an animated GIF at most $width wide,
     * with gif.cjs.
     *
     * @throws RuntimeException When the GIF isn't made.
     */
    public function makeGif(string $framesDir, string $gif, int $fps, int $width): void
    {
        $dir = dirname($gif);
        File::ensureDirectoryExists($dir);
        File::delete([$gif, "$dir/done.json"]);
        File::put("$dir/gif.json", json_encode(['framesDir' => $framesDir, 'out' => $gif, 'fps' => $fps, 'width' => $width, 'channel' => config('video-demo.recorder.channel')], JSON_UNESCAPED_SLASHES));

        $output = $this->run(static::script('gif.cjs'), "$dir/gif.json", 1800);
        if (! File::exists("$dir/done.json") || ! File::exists($gif)) {
            throw new RuntimeException('The GIF could not be made'.($output !== '' ? ': '.$output : '.'));
        }
    }

    /**
     * Draw numbered JPEG frames into a square or vertical frame, with
     * frame.cjs, writing them to $outDir. $job gives the sizes, words, look
     * and focus (see frame.cjs).
     *
     * @param  array<string, mixed>  $job
     *
     * @throws RuntimeException When the frames aren't made.
     */
    public function reframe(string $framesDir, string $outDir, array $job): void
    {
        File::ensureDirectoryExists($outDir);
        File::put("$outDir.json", json_encode(['framesDir' => $framesDir, 'outDir' => $outDir, 'channel' => config('video-demo.recorder.channel'), ...$job], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            $output = $this->run(static::script('frame.cjs'), "$outDir.json", 1800);
        } finally {
            File::delete("$outDir.json");
        }
        if (! File::exists("$outDir/done.json")) {
            throw new RuntimeException('The frames could not be drawn'.($output !== '' ? ': '.$output : '.'));
        }
        File::delete("$outDir/done.json");
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
            // This app: where the recorder signs in, and the API of a separate frontend.
            'baseUrl' => config('video-demo.recorder.base_url'),
            // Where pages open: a separate frontend's address, or this app's.
            'frontendUrl' => config('video-demo.frontend.url') ?: null,
            'loginPath' => SignIn::usesTokens() ? null : $this->loginPath($recordAs),
            'signInPath' => SignIn::usesTokens() ? $this->loginPath($recordAs, 'video-demo.studio.recorder-sign-in') : null,
            // A frontend's requests to this app reach the sample database through a header.
            'sandboxHeader' => $this->sandbox && config('video-demo.frontend.url') ? ['name' => Sandbox::HEADER, 'value' => $this->sandbox->cookie()['value']] : null,
            'viewport' => ['width' => $screen['width'], 'height' => $screen['height']],
            'touch' => $screen['touch'],
            'channel' => config('video-demo.recorder.channel'),
            'ignoreHttpsErrors' => (bool) config('video-demo.recorder.ignore_https_errors'),
            // Sends the browser's requests to the sample database, and asks for the pages in the video's language.
            'cookies' => array_values(array_filter([
                $this->sandbox ? [...$this->sandbox->cookie(), 'url' => rtrim((string) config('video-demo.recorder.base_url'), '/').'/'] : null,
                $this->locale ? ['name' => self::LOCALE_COOKIE, 'value' => $this->locale, 'url' => rtrim((string) config('video-demo.recorder.base_url'), '/').'/'] : null,
            ])),
            // A cookie or consent banner: accepted before recording (accept), left alone (leave), or the button to click (a CSS selector).
            'cookieBanner' => (string) (config('video-demo.recorder.cookie_banner') ?: 'accept'),
            // The browser's own language too, for apps that read Accept-Language or navigator.language.
            'locale' => $this->locale,
        ];
    }

    /**
     * Run a Node script, stopping it when $shouldStop says so or when it runs too long.
     */
    protected function run(string $script, string $job, int $timeout, ?Closure $shouldStop = null): string
    {
        $process = $this->start($script, $job, $timeout);

        while ($process->running()) {
            if ($shouldStop && $shouldStop()) {
                $this->end($process);

                throw new VideoStopped('Stopped by you.');
            }
            $process->ensureNotTimedOut();
            usleep(500_000);
        }

        return $this->output($process->wait());
    }

    /**
     * Start a Node script, with Playwright where Node looks for it.
     */
    protected function start(string $script, string $job, int $timeout): InvokedProcess|FakeInvokedProcess
    {
        return Process::timeout($timeout)
            ->env(['NODE_PATH' => implode(PATH_SEPARATOR, $this->nodePaths())])
            ->start([config('video-demo.recorder.node'), $script, $job]);
    }

    /**
     * End a script that is still running, and the browser it opened.
     */
    protected function end(InvokedProcess|FakeInvokedProcess $process): void
    {
        if (! $process->running()) {
            return;
        }
        if (defined('SIGTERM')) {
            $process->signal(SIGTERM);
            usleep(500_000);
            if ($process->running()) {
                $process->signal(SIGKILL);
            }
        } else {
            // Windows has no signals: stop it the way it can be.
            $process->stop(0.5);
        }
    }

    /**
     * Get what a finished script printed, saying so when Playwright or the GIF encoder is missing.
     */
    protected function output(ProcessResult $result): string
    {
        $output = trim($result->errorOutput() ?: $result->output());
        if (str_contains($output, "Cannot find module 'playwright'")) {
            throw new RuntimeException('Playwright is not installed. Run php artisan video-demo:install.');
        }
        if (str_contains($output, "Cannot find module 'gifenc'")) {
            throw new RuntimeException('The GIF encoder is not installed. Run php artisan video-demo:install.');
        }

        return $output;
    }

    /**
     * Build the error for a script that ended without writing its result,
     * with the end of what it printed, which says why.
     */
    protected static function noResult(string $what, string $output): RuntimeException
    {
        $output = trim($output);

        return new RuntimeException($output === ''
            ? "$what stopped without a result. Check that Node and Playwright work: php artisan video-demo:install"
            : "$what stopped without a result: ".Str::limit($output, 600).' If this keeps happening, check that Node and Playwright work: php artisan video-demo:install');
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
     * signed out: the login link, or for a separate frontend's tokens the
     * link the recorder fetches its sign-in from.
     */
    protected function loginPath(?string $recordAs, string $route = 'video-demo.studio.recorder-login'): ?string
    {
        // On sample data, the user is the one made in the sample database.
        $id = $this->sandbox ? $this->sandbox->userId() : RecordAs::resolve($recordAs)?->getAuthIdentifier();

        return $id === null ? null : $this->signedPath($id, $route);
    }

    /**
     * Get the sign-in link for each user the plan's sign_in steps sign in as,
     * keyed by the step's value: the login link, or for a separate
     * frontend's tokens the link its sign-in is fetched from.
     *
     * @return array<string, array{login?: string, signIn?: string}>
     *
     * @throws RuntimeException When a step's user can't be found.
     */
    protected function signInPaths(Plan $plan): array
    {
        $paths = [];
        foreach ($plan->steps as $step) {
            $value = trim((string) ($step['value'] ?? ''));
            if (($step['action'] ?? null) !== 'sign_in' || isset($paths[$value])) {
                continue;
            }

            // "member", "type:member", or empty for the default user.
            $as = $value === '' ? null : (str_starts_with($value, RecordAs::TYPE) ? $value : RecordAs::TYPE.$value);
            $id = $this->sandbox ? $this->sandbox->userFor($as) : RecordAs::resolve($as)?->getAuthIdentifier();
            if ($id === null) {
                throw new RuntimeException('A sign_in step has no user to sign in as: name a type of user in it, or set video-demo.recorder.login_as.');
            }

            $paths[$value] = SignIn::usesTokens()
                ? ['signIn' => $this->signedPath($id, 'video-demo.studio.recorder-sign-in')]
                : ['login' => $this->signedPath($id, 'video-demo.studio.recorder-login')];
        }

        return $paths;
    }

    /**
     * Get a signed, short-lived link to a recorder sign-in route for a user.
     */
    protected function signedPath(int|string $id, string $route): string
    {
        return URL::temporarySignedRoute(
            $route,
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
        } catch (RuntimeException $e) {
            // With no users table, checking the setting wouldn't help.
            throw RecordAs::missingUsersTable() ? $e : new RuntimeException("The recorder's user [$loginAs] was not found. Check video-demo.recorder.login_as.");
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
