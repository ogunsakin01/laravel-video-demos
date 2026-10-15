<?php

namespace Ogunsakin01\VideoDemo\Studio;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\RecordingFailed;
use Throwable;

/**
 * The full details of why a video failed, for the error view on its page:
 * the exception and its stack trace with the code around each frame, what
 * the recorder saw when a step failed (the page, a screenshot, the browser's
 * errors), the exceptions the app itself threw while being recorded, and the
 * video's settings.
 *
 * Only shown in the studio, to the developer, and never sent to an AI model.
 */
class ErrorReport
{
    /**
     * The most frames kept from a stack trace.
     */
    protected const MAX_FRAMES = 60;

    /**
     * The most frames that get the code around them.
     */
    protected const MAX_SNIPPETS = 15;

    /**
     * Build the report for a video that failed.
     *
     * @param  list<array<string, mixed>>  $appErrors
     * @return array<string, mixed>
     */
    public static function for(Video $video, Throwable $e, array $appErrors = []): array
    {
        $step = null;
        if ($e instanceof RecordingFailed) {
            $result = $e->result;
            $step = [
                'number' => $result->failedStep,
                'device' => $result->device ? Devices::get($result->device)['label'] : null,
                'plan' => $e->step ? static::withoutNulls($e->step) : null,
                'error' => $result->errorFull ?: $result->error,
                'url' => $result->url,
                'title' => $result->title,
                'snapshot' => $result->snapshot,
                'screenshot' => static::keepScreenshot($video, $result->screenshot),
                'browser' => array_slice($result->browserLog, -40),
                'app_error' => $result->appError,
            ];
        }

        return [
            'at' => now()->toIso8601String(),
            'exception' => static::exception($e),
            'step' => $step,
            'app_errors' => $appErrors,
            'issues' => $video->issues ?? [],
            'context' => static::context($video),
        ];
    }

    /**
     * Write the report as Markdown, to paste into an issue or an AI chat.
     * Vendor frames and the page structure are left out to keep it short.
     *
     * @param  array<string, mixed>  $report
     */
    public static function markdown(array $report): string
    {
        $e = $report['exception'];
        $md = ["# {$e['class']}", '', $e['message'], '', "at `{$e['file']}:{$e['line']}`"];

        if ($step = $report['step'] ?? null) {
            $md[] = "\n## Where the recording stopped\n";
            $md[] = 'Step '.$step['number'].($step['device'] ? " ({$step['device']})" : '').' on '.$step['url'].($step['title'] ? " ({$step['title']})" : '');
            if ($step['plan']) {
                $md[] = "\n```json\n".json_encode($step['plan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n```";
            }
            $md[] = "\n```\n{$step['error']}\n```";
            $browser = array_map(fn ($l) => '- '.$l['kind'].(isset($l['status']) ? " {$l['status']}" : '').': '.$l['text'], $step['browser'] ?? []);
            if ($browser !== []) {
                $md[] = "\n### Browser\n\n".implode("\n", $browser);
            }
        }

        foreach ($report['app_errors'] ?? [] as $error) {
            $md[] = "\n## Error in the app: {$error['class']}\n\n{$error['message']}\n\n{$error['method']} {$error['url']}, at `{$error['file']}:{$error['line']}`";
            $md[] = static::markdownFrames($error['frames'] ?? []);
        }

        $md[] = "\n## Stack trace\n";
        $md[] = static::markdownFrames($e['frames'] ?? []);

        foreach ($report['context'] ?? [] as $title => $values) {
            $md[] = "\n## $title\n";
            foreach ($values as $key => $value) {
                $md[] = "- $key: $value";
            }
        }

        return trim(implode("\n", $md))."\n";
    }

    /**
     * Write the app's own frames as a numbered list, with the code of the first.
     *
     * @param  list<array<string, mixed>>  $frames
     */
    protected static function markdownFrames(array $frames): string
    {
        $lines = [];
        $coded = false;
        foreach ($frames as $i => $frame) {
            if ($frame['vendor'] && $i > 0) {
                continue;
            }
            $lines[] = ($i + 1).'. `'.$frame['call'].'`'.($frame['file'] ? " at `{$frame['file']}:{$frame['line']}`" : '');
            if (! $coded && ! empty($frame['code'])) {
                $coded = true;
                $code = [];
                foreach ($frame['code']['lines'] as $n => $text) {
                    $number = $frame['code']['start'] + $n;
                    $code[] = ($number === $frame['line'] ? '> ' : '  ').str_pad((string) $number, 4).$text;
                }
                $lines[] = "\n```\n".implode("\n", $code)."\n```\n";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Describe an exception: its class, message, where it was thrown, its
     * stack trace and the exceptions that led to it.
     *
     * @return array<string, mixed>
     */
    public static function exception(Throwable $e): array
    {
        $previous = [];
        for ($p = $e->getPrevious(); $p && count($previous) < 5; $p = $p->getPrevious()) {
            $previous[] = ['class' => $p::class, 'message' => $p->getMessage(), 'file' => static::relative($p->getFile()), 'line' => $p->getLine()];
        }

        return [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => static::relative($e->getFile()),
            'line' => $e->getLine(),
            'frames' => static::frames($e),
            'previous' => $previous,
        ];
    }

    /**
     * Get the stack trace as frames, where each one ran and what it called,
     * like Laravel's error page: the app's own frames with the code around
     * them, vendor frames marked so they can be folded away.
     *
     * @return list<array{file: ?string, line: ?int, call: string, vendor: bool, code: ?array{start: int, lines: list<string>}}>
     */
    public static function frames(Throwable $e): array
    {
        $trace = $e->getTrace();
        $frames = [];
        $file = $e->getFile();
        $line = $e->getLine();
        $snippets = 0;

        foreach (array_merge($trace, [['function' => '{main}']]) as $call) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
            $vendor = $file === '' || static::isVendor($file);
            // The frame where it was thrown always gets its code, even in vendor.
            $code = ($frames === [] || ! $vendor) && $snippets < self::MAX_SNIPPETS && $file !== '' ? static::snippet($file, $line) : null;
            $snippets += $code ? 1 : 0;

            $frames[] = [
                'file' => $file !== '' ? static::relative($file) : null,
                'line' => $line ?: null,
                'call' => isset($call['class']) ? $call['class'].($call['type'] ?? '::').$call['function'].'()' : ($call['function'] === '{main}' ? '{main}' : $call['function'].'()'),
                'vendor' => $vendor,
                'code' => $code,
            ];

            $file = (string) ($call['file'] ?? '');
            $line = (int) ($call['line'] ?? 0);
        }

        return $frames;
    }

    /**
     * Get the lines around a line of a file.
     *
     * @return ?array{start: int, lines: list<string>}
     */
    public static function snippet(string $file, int $line, int $around = 7): ?array
    {
        if ($line < 1 || ! is_file($file) || ! is_readable($file) || filesize($file) > 2_000_000) {
            return null;
        }
        $all = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $start = max(1, $line - $around);

        return ['start' => $start, 'lines' => array_map(fn ($l) => mb_strimwidth((string) $l, 0, 300, '…'), array_slice($all, $start - 1, $around * 2 + 1))];
    }

    /**
     * Get a path relative to the app, as Laravel's error page shows it.
     */
    public static function relative(string $file): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * Whether a file is outside the app's own code.
     */
    protected static function isVendor(string $file): bool
    {
        return str_contains($file, '/vendor/') || ! str_starts_with($file, rtrim(base_path(), '/').'/');
    }

    /**
     * Drop the empty fields of a step, at every level, so it reads as written.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected static function withoutNulls(array $values): array
    {
        $values = array_map(fn ($v) => is_array($v) ? static::withoutNulls($v) : $v, $values);

        return array_filter($values, fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Copy the recorder's screenshot of the failed page next to the video, so
     * it outlives the next attempt. Returns whether there is one.
     */
    protected static function keepScreenshot(Video $video, ?string $screenshot): bool
    {
        if (! $screenshot || ! is_file($screenshot)) {
            return false;
        }
        Storage::disk(config('video-demo.storage.work_disk'))->put($video->workPath('error.jpg'), File::get($screenshot));

        return true;
    }

    /**
     * Get the video's settings and the software it was made with.
     *
     * @return array<string, array<string, string>>
     */
    protected static function context(Video $video): array
    {
        $yes = fn ($v) => $v ? 'Yes' : 'No';

        return [
            'Video' => array_filter([
                'ID' => (string) $video->id,
                'Title' => (string) $video->title,
                'Description' => $video->prompt,
                'Start page' => $video->start_path ?: '/',
                'Recorded as' => (string) $video->record_as,
                'Sample data' => $yes($video->sample_data),
                'Versions' => implode(', ', $video->deviceList()),
                'Style' => (string) ($video->style['name'] ?? ''),
                'Model' => trim($video->model.' '.$video->effort),
                'Length' => $video->target_seconds.'s target',
                'Playback speed' => $video->playbackSpeed().'×',
                'Language' => $video->languageCode().($video->language ? '' : ' (the app\'s)'),
                'Voice' => (string) $video->voice,
                'Music' => (string) $video->music,
                'AI cost' => sprintf('$%.4f of $%.2f', $video->cost, $video->spendLimit()),
            ], fn ($v) => $v !== ''),
            'Environment' => [
                'App URL' => (string) (config('video-demo.recorder.base_url') ?: config('app.url')),
                'App environment' => (string) app()->environment(),
                'PHP' => PHP_VERSION,
                'Laravel' => Application::VERSION,
                'laravel-video-demos' => (string) (InstalledVersions::isInstalled('ogunsakin01/laravel-video-demos') ? InstalledVersions::getPrettyVersion('ogunsakin01/laravel-video-demos') : 'unknown'),
                'Browser' => (string) (config('video-demo.recorder.channel') ?: 'Playwright Chromium'),
                'Queue' => (string) (config('video-demo.queue.connection') ?: 'video-demo'),
            ],
        ];
    }
}
