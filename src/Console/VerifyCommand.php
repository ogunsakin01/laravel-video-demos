<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\Verifier;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use RuntimeException;
use Throwable;

/**
 * Checks that videos still work in the app after it changed, without
 * recording them and at no AI cost, so a renamed button or a moved page is
 * found before users watch an out-of-date video. Made for CI: it exits
 * non-zero when a video is broken.
 */
class VerifyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:verify
        {slug?* : Only these videos}
        {--all : Check every video with a plan, not only published ones}
        {--changed= : Only the videos made from code that differs from this git ref, such as origin/main}
        {--fix : Ask the AI for a new plan for each broken video, record it, and publish it again if it was published}
        {--json : Print the result as JSON, for CI, scripts and AI agents}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check that videos still play in the app, without recording them or spending on AI';

    /**
     * Execute the console command.
     */
    public function handle(Verifier $verifier, Publisher $publisher): int
    {
        if (! VideoDemoServiceProvider::studioEnabled()) {
            return $this->refuse('The studio is disabled here, and checks sign in through it. Run this where videos are made, or set VIDEO_DEMO_STUDIO=true (in CI too).');
        }

        $videos = $this->videos();
        $changed = null;
        if ($ref = $this->option('changed')) {
            try {
                $changed = Verifier::changedFiles($ref);
            } catch (RuntimeException $e) {
                return $this->refuse($e->getMessage());
            }
        }

        $report = [];
        $failed = 0;
        foreach ($videos as $video) {
            $row = ['slug' => $video->slug, 'title' => $video->title ?: $video->slug, 'studio_url' => route('video-demo.studio.show', $video)];

            if ($changed !== null) {
                $touched = Verifier::touched($video, $changed);
                if ($touched === []) {
                    $report[] = [...$row, 'status' => 'skipped', 'reason' => 'None of its code changed.'];

                    continue;
                }
                $row['changed'] = $touched;
            }

            $result = $this->check($video, $verifier);
            $row = [...$row, 'status' => $result['ok'] ? 'ok' : 'broken', ...array_intersect_key($result, array_flip(['device', 'step', 'action', 'error', 'url', 'issues']))];

            if (! $result['ok'] && $this->option('fix')) {
                $row['fix'] = $this->fix($video, $publisher);
                $row['status'] = $row['fix']['status'] === 'fixed' ? 'fixed' : 'broken';
            }
            if ($row['status'] === 'broken') {
                $failed++;
            }
            $report[] = $row;
        }

        $summary = [
            'ok' => $failed === 0,
            'checked' => count(array_filter($report, fn ($r) => $r['status'] !== 'skipped')),
            'broken' => $failed,
            'fixed' => count(array_filter($report, fn ($r) => $r['status'] === 'fixed')),
            'skipped' => count(array_filter($report, fn ($r) => $r['status'] === 'skipped')),
            'videos' => $report,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->print($summary);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Get the videos to check: those named, else the published ones (or
     * all with --all). Videos being made are left out.
     *
     * @return Collection<int, Video>
     */
    protected function videos(): Collection
    {
        return Video::query()
            ->when($this->argument('slug'), fn ($q, $slugs) => $q->whereIn('slug', $slugs), fn ($q) => $this->option('all') ? $q : $q->whereNotNull('published_at'))
            ->orderBy('title')
            ->get()
            ->reject(fn (Video $v) => $v->status->isWorking() || empty($v->plan['steps']))
            ->values();
    }

    /**
     * Check one video, showing that it is being checked.
     *
     * @return array<string, mixed>
     */
    protected function check(Video $video, Verifier $verifier): array
    {
        if ($this->option('json')) {
            return $verifier->verify($video);
        }

        $result = null;
        $this->components->task($video->title ?: $video->slug, function () use ($video, $verifier, &$result) {
            $result = $verifier->verify($video);

            return $result['ok'];
        });

        return $result;
    }

    /**
     * Make a broken video again with a new plan, and publish it again if it
     * was published.
     *
     * @return array{status: string, error?: ?string, cost?: float}
     */
    protected function fix(Video $video, Publisher $publisher): array
    {
        $before = $video->cost;
        $video->update(['status' => VideoStatus::Queued, 'error' => null, 'stop_requested' => false, 'issues' => null]);
        $video->addLog('Asking the AI for a new plan: the check found the old one no longer works');

        try {
            GenerateVideo::dispatchSync($video, replan: true);
        } catch (Throwable) {
            // The job saves the error on the video.
        }
        $video->refresh();

        if ($video->status !== VideoStatus::Ready) {
            return ['status' => 'failed', 'error' => $video->error, 'cost' => round($video->cost - $before, 4)];
        }
        if ($video->isPublished()) {
            $publisher->publish($video);
        }
        $video->forceFill(['verified_at' => now(), 'verify_result' => ['status' => 'done', 'ok' => true, 'checked' => $video->recordedDevices()]])->save();

        return ['status' => 'fixed', 'cost' => round($video->cost - $before, 4)];
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
            if ($row['status'] === 'broken') {
                $where = $row['step'] ? "Step {$row['step']}".($row['action'] ? " ({$row['action']})" : '').($row['device'] ? ' on '.Devices::get($row['device'])['label'] : '').': ' : '';
                $this->components->error("{$row['title']}: $where{$row['error']}");
                if (! empty($row['fix']['error'])) {
                    $this->components->twoColumnDetail('Fix', 'failed: '.$row['fix']['error']);
                }
                $this->components->twoColumnDetail('Studio', $row['studio_url']);
            }
            if ($row['status'] === 'fixed') {
                $this->components->info(sprintf('%s: fixed with a new plan, for $%.4f.', $row['title'], $row['fix']['cost'] ?? 0));
            }
        }

        if ($summary['checked'] === 0) {
            $this->components->info($summary['skipped'] ? 'No video was made from the changed code.' : 'No videos to check. Publish one, or pass --all.');

            return;
        }

        $line = sprintf('%d checked, %d broken', $summary['checked'], $summary['broken'])
            .($summary['fixed'] ? ", {$summary['fixed']} fixed" : '')
            .($summary['skipped'] ? ", {$summary['skipped']} skipped (none of their code changed)" : '').'.';
        $summary['broken'] ? $this->components->warn($line.' Fix them in the studio, or run this again with --fix.') : $this->components->info($line);
    }

    /**
     * Print why the check couldn't start, in the chosen format.
     */
    protected function refuse(string $message): int
    {
        $this->option('json')
            ? $this->line(json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES))
            : $this->components->error($message);

        return self::FAILURE;
    }
}
