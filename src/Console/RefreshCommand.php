<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Verifier;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Ogunsakin01\VideoDemo\Voice\Voices;
use RuntimeException;
use Throwable;

/**
 * Records published videos again from their stored plans and republishes
 * them, after the app changed. The model is not asked, so it costs nothing.
 * With --changed only the videos made from code that changed are recorded,
 * and with --fix a video whose plan no longer works gets a new one: the
 * step a deploy pipeline runs to keep the library up to date.
 */
class RefreshCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:refresh
        {slug?* : Only these videos}
        {--changed= : Only the videos made from code that differs from this git ref, such as the commit deployed before}
        {--fix : When a plan no longer works, ask the AI for a new one (this costs what a new plan costs)}
        {--json : Print the result as JSON, for scripts and CI}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Record published videos again from their saved plans and republish them';

    /**
     * Execute the console command.
     */
    public function handle(Publisher $publisher): int
    {
        if (! VideoDemoServiceProvider::studioEnabled()) {
            return $this->refuse('The studio is disabled here, and recording signs in through it. Run this where videos are made, or set VIDEO_DEMO_STUDIO=true (in CI too).');
        }

        $changed = null;
        if ($ref = $this->option('changed')) {
            try {
                $changed = Verifier::changedFiles($ref);
            } catch (RuntimeException $e) {
                return $this->refuse($e->getMessage());
            }
        }

        $report = [];
        foreach ($this->videos() as $video) {
            $row = ['slug' => $video->slug, 'title' => $video->title ?: $video->slug];

            if ($video->status->isWorking()) {
                $report[] = [...$row, 'status' => 'skipped', 'reason' => 'It is being made now.'];

                continue;
            }
            if ($changed !== null) {
                $touched = Verifier::touched($video, $changed);
                // Recording is slow, so a video whose code isn't known isn't
                // recorded on every change, as a check would be.
                $skip = match (true) {
                    $touched === [] => 'None of its code changed.',
                    $touched === null => "The code it was made from isn't known. Record it once with video-demo:refresh {$video->slug}, or plan it again to note its code.",
                    default => null,
                };
                if ($skip !== null) {
                    $report[] = [...$row, 'status' => 'skipped', 'reason' => $skip];

                    continue;
                }
            }

            // Recorded without its voice, it would be published silent.
            if ($video->voice && ! Voices::available($video->voice)) {
                $report[] = [...$row, 'status' => 'failed', 'voice' => $video->voice, 'error' => $this->voiceMissing($video->voice)];

                continue;
            }

            $report[] = [...$row, ...$this->refresh($video, $publisher)];
        }

        $summary = [
            'ok' => ! collect($report)->contains('status', 'failed'),
            'refreshed' => collect($report)->where('status', 'refreshed')->count(),
            'fixed' => collect($report)->where('status', 'fixed')->count(),
            'failed' => collect($report)->where('status', 'failed')->count(),
            'skipped' => collect($report)->where('status', 'skipped')->count(),
            'videos' => $report,
        ];

        $this->option('json')
            ? $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            : $this->print($summary);

        return $summary['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Say why a video's voice isn't available here, and how to get it.
     */
    protected function voiceMissing(string $voice): string
    {
        [$provider] = Voices::parse($voice);
        $how = match ($provider) {
            'kokoro' => 'Run php artisan video-demo:install-voice first (in CI too).',
            'system' => "It is a computer's own voice, and this computer doesn't have it. Choose another voice for the video.",
            'openai', 'gemini', 'elevenlabs' => 'Set the '.strtoupper($provider).'_API_KEY here (in CI too).',
            default => 'Choose another voice for the video.',
        };

        return 'Its voice, '.Voices::label($voice).", isn't available here, so it would be published without one. $how";
    }

    /**
     * Get the videos to record again: those named, else the published ones
     * that have a plan.
     *
     * @return Collection<int, Video>
     */
    protected function videos(): Collection
    {
        return Video::query()
            ->when($this->argument('slug'), fn ($q, $slugs) => $q->whereIn('slug', $slugs), fn ($q) => $q->whereNotNull('published_at'))
            ->orderBy('title')
            ->get()
            ->filter(fn (Video $video) => $video->hasPlan())
            ->values();
    }

    /**
     * Record one video again from its plan, or with --fix from a new plan
     * when the old one no longer works, and republish it.
     *
     * @return array{status: string, error?: ?string, cost?: float}
     */
    protected function refresh(Video $video, Publisher $publisher): array
    {
        $result = [];
        $run = function () use ($video, $publisher, &$result) {
            $before = $video->cost;
            $status = 'refreshed';
            $error = $this->record($video, 'Queued to record the same plan again', replan: false);
            if ($error !== null && $this->option('fix')) {
                $status = 'fixed';
                $error = $this->record($video, 'Asking the AI for a new plan: the old one no longer works with the app', replan: true);
            }

            if ($error === null && $video->isPublished()) {
                $publisher->publish($video);
            }
            $result = array_filter([
                'status' => $error === null ? $status : 'failed',
                'error' => $error,
                'cost' => round($video->cost - $before, 4),
            ], fn ($value) => $value !== null);

            return $error === null;
        };

        $this->option('json') ? $run() : $this->components->task($video->title ?: $video->slug, $run);

        return $result;
    }

    /**
     * Make the video, waiting for it. Returns null when it is ready,
     * otherwise why it failed.
     */
    protected function record(Video $video, string $log, bool $replan): ?string
    {
        $video->update(['status' => VideoStatus::Queued, 'error' => null, 'stop_requested' => false, 'issues' => null]);
        $video->addLog($log);

        try {
            GenerateVideo::dispatchSync($video, replan: $replan);
        } catch (Throwable) {
            // The job saves the error on the video.
        }
        $video->refresh();

        return $video->status === VideoStatus::Ready ? null : ($video->error ?? 'Recording failed.');
    }

    /**
     * Print the result for a person.
     *
     * @param  array<string, mixed>  $summary
     */
    protected function print(array $summary): void
    {
        $this->newLine();
        foreach ($summary['videos'] as $row) {
            if ($row['status'] === 'failed') {
                $this->components->error("{$row['title']}: {$row['error']}".($this->option('fix') || isset($row['voice']) ? '' : ' Run again with --fix to ask the AI for a new plan, or open it in the studio.'));
            }
            if ($row['status'] === 'fixed') {
                $this->components->info(sprintf('%s: its plan no longer worked, so it was made with a new one, for $%.4f.', $row['title'], $row['cost'] ?? 0));
            }
        }

        $done = $summary['refreshed'] + $summary['fixed'];
        if ($done + $summary['failed'] === 0) {
            $this->components->info($this->option('changed') ? 'No published video was made from the changed code.' : 'No published videos to record again.');

            return;
        }
        $this->components->info(sprintf('Recorded %d %s again%s.', $done, $done === 1 ? 'video' : 'videos', $summary['skipped'] ? ", skipped {$summary['skipped']}" : ''));
    }

    /**
     * Stop with an error, as JSON with --json.
     */
    protected function refuse(string $message): int
    {
        $this->option('json')
            ? $this->line(json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES))
            : $this->components->error($message);

        return self::FAILURE;
    }
}
