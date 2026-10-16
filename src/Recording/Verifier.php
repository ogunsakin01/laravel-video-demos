<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Events\VideoBroke;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Ogunsakin01\VideoDemo\Support\Routes;
use RuntimeException;
use Throwable;

/**
 * Checks that a video's plan still works in the app, after the app has
 * changed: its pages are opened and its steps played in a browser on each
 * version it was recorded in, as fast as they go and without recording. It
 * costs no AI and changes nothing but the video's check result.
 */
class Verifier
{
    /**
     * Create a new verifier instance.
     */
    public function __construct(protected Recorder $recorder) {}

    /**
     * Check a video and save the result on it. A video that fails fires
     * VideoBroke.
     *
     * @return array{status: string, ok: bool, checked: list<string>, device: ?string, step: ?int, action: ?string, error: ?string, url: ?string, issues: list<array<string, mixed>>, screenshot: bool}
     */
    public function verify(Video $video): array
    {
        $result = ['status' => 'done', 'ok' => true, 'checked' => [], 'device' => null, 'step' => null, 'action' => null, 'error' => null, 'url' => null, 'issues' => [], 'screenshot' => false];
        $work = Storage::disk(config('video-demo.storage.work_disk'));
        $work->delete($video->workPath('verify.jpg'));
        $sandbox = null;
        $this->recorder->locale = $video->recordingLocale();

        try {
            if (empty($video->plan['steps'])) {
                throw new RuntimeException('This video has no plan to check yet.');
            }
            $plan = Plan::fromArray($video->plan);

            if ($video->sample_data) {
                $sandbox = Sandbox::prepareFor($video, true, fn () => null);
                $this->recorder->sandbox = $sandbox;
            }

            $devices = $video->recordedDevices() ?: $video->deviceList();
            if (config('video-demo.recorder.check_pages', true) && ($issues = $this->recorder->checkPages($plan->paths(), $video->record_as, $devices[0])) !== []) {
                $result = [...$result, 'ok' => false, 'issues' => $issues, 'url' => $issues[0]['url'] ?? null,
                    'error' => count($issues) === 1 ? "{$issues[0]['path']}: {$issues[0]['problem']}." : count($issues).' of its pages fail to open.'];
            }

            foreach ($result['ok'] ? $devices : [] as $device) {
                $dir = Recorder::workDir($video->workPath("verify/$device"));
                try {
                    $run = $this->recorder->verify($plan, $dir, $video->style ?? [], $device, $video->record_as);
                    $result['checked'][] = $device;
                    if (! $run->ok) {
                        $steps = $plan->forDevice($device)->steps;
                        $result = [...$result, 'ok' => false, 'device' => $device, 'step' => $run->failedStep, 'url' => $run->url,
                            'action' => $run->failedStep ? ($steps[$run->failedStep - 1]['action'] ?? null) : null,
                            'error' => $run->error ?: 'The check stopped without saying why.',
                            'issues' => ($issue = $run->issue()) ? [$issue] : []];
                        if ($run->screenshot && File::exists($run->screenshot)) {
                            $work->put($video->workPath('verify.jpg'), File::get($run->screenshot));
                            $result['screenshot'] = true;
                        }

                        break;
                    }
                } finally {
                    File::deleteDirectory($dir);
                }
            }
        } catch (Throwable $e) {
            $result = [...$result, 'ok' => false, 'error' => $e->getMessage()];
        } finally {
            $sandbox?->delete();
            $this->recorder->sandbox = null;
            $this->recorder->locale = null;
        }

        $video->forceFill(['verified_at' => now(), 'verify_result' => $result])->save();
        if (! $result['ok']) {
            VideoBroke::dispatch($video, $result);
        }

        return $result;
    }

    /**
     * Get the files a video was made from, relative to the app's root: the
     * code the AI read while planning it, and the controllers or route files
     * behind each page it opens.
     *
     * @return list<string>
     */
    public static function sourceFiles(Video $video): array
    {
        $files = $video->source_files ?? [];
        foreach (Plan::fromArray($video->plan ?? [])->paths() as $path) {
            $files = [...$files, ...Routes::files($path)];
        }
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * Get the files that differ from a git ref, such as origin/main,
     * including changes not committed yet, relative to the app's root.
     *
     * @return list<string>
     *
     * @throws RuntimeException When git can't compare them.
     */
    public static function changedFiles(string $ref): array
    {
        if (str_starts_with($ref, '-')) {
            throw new RuntimeException("[$ref] isn't a git ref.");
        }
        $result = Process::path(base_path())->run(['git', 'diff', '--name-only', $ref, '--']);
        if ($result->failed()) {
            throw new RuntimeException("git couldn't compare with [$ref]: ".trim($result->errorOutput() ?: $result->output()));
        }

        return array_values(array_filter(array_map('trim', explode("\n", $result->output()))));
    }

    /**
     * Determine which of a video's files are among the changed ones. A video
     * planned before the files it was made from were noted is matched on the
     * controllers and route files behind its pages; one with no files known
     * at all may be affected by any change, so it returns null.
     *
     * @param  list<string>  $changed
     * @return ?list<string>
     */
    public static function touched(Video $video, array $changed): ?array
    {
        $files = static::sourceFiles($video);
        if ($video->source_files === null && $files === []) {
            return null;
        }

        return array_values(array_intersect($files, $changed));
    }
}
