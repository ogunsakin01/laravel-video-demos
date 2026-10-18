<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Preview;
use Ogunsakin01\VideoDemo\Recording\Verifier;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use RuntimeException;

/**
 * Records the published videos a change touches into a folder, from their
 * saved plans, as MP4s and GIFs with a summary.md to post on a pull request:
 * reviewers see how each how-to looks with the change, and which no longer
 * works. Nothing is published or changed, and no AI is used.
 */
class PreviewCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:preview
        {slug?* : Only these videos}
        {--changed= : Only the published videos made from code that differs from this git ref, such as the pull request\'s base}
        {--frontend-changed= : Also the videos made from a separate frontend\'s code that differs from this git ref in its repository (VIDEO_DEMO_FRONTEND_PATH)}
        {--out=video-demo-previews : The folder the previews and summary.md go in}
        {--no-gif : Make only the MP4s}
        {--json : Print the result as JSON, for scripts and CI}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Record videos a change touches into a folder, with GIFs and a summary for a pull request, without publishing them';

    /**
     * Execute the console command.
     */
    public function handle(Preview $preview): int
    {
        if (! VideoDemoServiceProvider::studioEnabled()) {
            return $this->refuse('The studio is disabled here, and recording signs in through it. Set VIDEO_DEMO_STUDIO=true (in CI too).');
        }

        $videos = $this->videos();
        if (($ref = $this->option('changed')) || $this->option('frontend-changed')) {
            try {
                $changed = Verifier::changedFiles($ref ? (string) $ref : null, $this->option('frontend-changed') ?: null);
            } catch (RuntimeException $e) {
                return $this->refuse($e->getMessage());
            }
            $videos = $videos->filter(fn (Video $video) => (Verifier::touched($video, $changed) ?? []) !== [])->values();
        }

        $out = (string) $this->option('out');
        $out = str_starts_with($out, '/') ? $out : base_path($out);
        File::ensureDirectoryExists($out);

        /** @var list<array<string, mixed>> $results */
        $results = [];
        foreach ($videos as $video) {
            $run = fn () => $preview->make($video, $out, ! $this->option('no-gif'));
            $result = $this->option('json') ? $run() : null;
            if ($result === null) {
                $this->components->task($video->title ?: $video->slug, function () use ($run, &$result) {
                    $result = $run();

                    return $result['ok'];
                });
            }
            $results[] = ['slug' => $video->slug, 'title' => $video->title ?: $video->slug, ...$result];
        }

        File::put("$out/summary.md", $this->summary($results, (bool) ($this->option('changed') || $this->option('frontend-changed'))));
        $ok = ! in_array(false, array_column($results, 'ok'), true);

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $ok, 'out' => $out, 'videos' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->newLine();
            $results === []
                ? $this->components->info(($this->option('changed') || $this->option('frontend-changed')) ? 'No published video was made from the changed code.' : 'No published videos to preview.')
                : $this->components->info(sprintf('%d %s in %s, with summary.md.', count($results), count($results) === 1 ? 'preview' : 'previews', $out));
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Get the videos to preview: those named, else the published ones with a plan.
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
     * Write the summary for a pull request comment.
     *
     * @param  list<array<string, mixed>>  $results
     */
    protected function summary(array $results, bool $changed): string
    {
        if ($results === []) {
            return $changed ? "## How-to videos\n\nThis change touches none of the published how-to videos.\n" : "## How-to videos\n\nThere were no videos to preview.\n";
        }

        $broken = count(array_filter($results, fn ($r) => ! $r['ok']));
        $lines = [
            $changed ? '## How-to videos this change touches' : '## How-to videos',
            '',
            $broken
                ? sprintf('%d of %d no longer %s: fix the app, or plan %s again in the studio.', $broken, count($results), $broken === 1 ? 'works' : 'work', $broken === 1 ? 'it' : 'them')
                : 'Each one still works. The recordings are in this run\'s artifacts.',
            '',
            '| Video | Result | Files |',
            '| --- | --- | --- |',
        ];
        foreach ($results as $r) {
            $result = $r['ok']
                ? 'Still works'.($r['seconds'] ? sprintf(' (%d seconds)', (int) round($r['seconds'])) : '')
                : 'Broken'.($r['step'] ? " at step {$r['step']}" : '').': '.str_replace(['|', "\n"], ['\\|', ' '], (string) $r['error']);
            $files = implode(', ', array_map(fn ($f) => '`'.basename((string) $f).'`', array_filter([$r['mp4'], $r['gif']])));
            $lines[] = '| '.str_replace('|', '\\|', (string) $r['title'])." | $result | $files |";
        }

        return implode("\n", $lines)."\n";
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
