<?php

namespace Ogunsakin01\VideoDemo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Placer;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use Ogunsakin01\VideoDemo\Studio\ViewFinder;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Throwable;

/**
 * Suggests where in the app a finished video should go, and shows it there:
 * the pages the video visits are opened as its user (on its sample data, made
 * again), the AI picks two or three sections, and each is photographed with a
 * player card in place, on a desktop and a phone screen. Each spot comes with
 * the view and line to paste the player tag at, when a view shows it.
 */
class FindSpots implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * The most pages looked at.
     */
    public const MAX_PAGES = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public Video $video)
    {
        $this->onConnection(config('video-demo.queue.connection') ?: VideoDemoServiceProvider::QUEUE_CONNECTION);
        $this->onQueue(config('video-demo.queue.name'));
    }

    /**
     * Execute the job.
     */
    public function handle(Recorder $recorder, Placer $placer): void
    {
        $video = $this->video;
        $started = now()->toIso8601String();
        $sandbox = null;

        try {
            if ($video->sample_data) {
                $sandbox = Sandbox::create($video);
                $sandbox->prepare($video->record_as);
                if ($video->sample_records) {
                    $sandbox->seed($video->sample_records);
                }
                $recorder->sandbox = $sandbox;
            }

            $map = $recorder->mapPages($this->paths($video), $video->record_as);
            $pages = array_values(array_filter($map['pages'], fn ($p) => $p['sections'] !== []));
            if ($pages === []) {
                throw new \RuntimeException('None of the video\'s pages could be opened'.($map['failed'] ? ': '.$map['failed'][0]['path'].' gave '.$map['failed'][0]['reason'] : '').'.');
            }

            [$spots, $note] = $this->choose($video, $pages, $placer);
            if ($spots === []) {
                throw new \RuntimeException('No section of the video\'s pages could hold it.');
            }

            $dir = Storage::disk(config('video-demo.storage.work_disk'))->path($video->workPath('spots'));
            File::deleteDirectory($dir);
            $device = $video->primaryDevice();
            $shots = $recorder->shootSpots(
                array_map(fn ($s) => ['path' => $s['path'], 'selector' => $s['section']['selector'], 'position' => $s['position']], $spots),
                [
                    'poster' => Storage::disk(config('video-demo.storage.work_disk'))->path($video->workFile($device, 'poster')),
                    'title' => $video->title ?: ($video->plan['title'] ?? 'How-to video'),
                    'length' => sprintf('%d:%02d', intdiv((int) round($video->duration_seconds ?? 0), 60), (int) round($video->duration_seconds ?? 0) % 60),
                    'accent' => '#e5693d',
                ],
                $dir,
                ['desktop', 'mobile'],
                $video->record_as,
            );

            $items = [];
            foreach ($spots as $i => $spot) {
                $files = collect($shots['shots'])->where('spot', $i)->mapWithKeys(fn ($s) => [$s['screen'] => basename($s['file'])])->all();
                if ($files === []) {
                    continue;
                }
                $items[] = [
                    'path' => $spot['path'],
                    'label' => $spot['label'],
                    'why' => $spot['why'],
                    'position' => $spot['position'],
                    'heading' => $spot['section']['heading'],
                    'selector' => $spot['section']['selector'],
                    'shots' => $files,
                    'code' => ViewFinder::find($spot['section']['heading'] ?: '', $spot['path']),
                ];
            }

            if ($items === []) {
                throw new \RuntimeException('The video could not be shown in any of the spots: '.($shots['missing'][0]['reason'] ?? 'the sections were not found').'.');
            }

            $video->update(['spots' => ['status' => 'ready', 'at' => $started, 'note' => $note, 'items' => $items]]);
        } catch (Throwable $e) {
            $video->update(['spots' => ['status' => 'failed', 'at' => $started, 'error' => $e->getMessage(), 'items' => []]]);
        } finally {
            $sandbox?->delete();
            $recorder->sandbox = null;
        }
    }

    /**
     * Get the pages to look at: the ones the video opens, in order, starting
     * with its start page.
     *
     * @return list<string>
     */
    protected function paths(Video $video): array
    {
        $paths = [$video->start_path ?: null];
        foreach ($video->plan['steps'] ?? [] as $step) {
            if (($step['action'] ?? '') === 'goto' && is_string($step['url'] ?? null) && str_starts_with($step['url'], '/')) {
                $paths[] = $step['url'];
            }
        }

        return array_slice(array_values(array_unique(array_filter($paths))), 0, self::MAX_PAGES) ?: ['/'];
    }

    /**
     * Pick the spots: with AI when a key is set and the video's spending limit
     * allows, otherwise the top of its first page. Returns the spots and a
     * note on how they were picked, if not by AI.
     *
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    protected function choose(Video $video, array $pages, Placer $placer): array
    {
        if (! Drivers::anyKey()) {
            return [Placer::fallback($pages), 'With no AI key set, this is the top of the page the video starts on.'];
        }
        if ($video->cost >= $video->spendLimit()) {
            return [Placer::fallback($pages), sprintf('The video has reached its $%.2f spending limit, so this is the top of the page it starts on, picked without AI.', $video->spendLimit())];
        }

        return [$placer->place($video, $pages, Pricing::defaultModel()), null];
    }

    /**
     * Handle a job that failed outside handle(), such as a timeout.
     */
    public function failed(?Throwable $e): void
    {
        $spots = $this->video->fresh()?->spots;
        if (($spots['status'] ?? null) === 'working') {
            $this->video->update(['spots' => ['status' => 'failed', 'at' => $spots['at'], 'error' => $e?->getMessage() ?: 'It stopped before it finished.', 'items' => []]]);
        }
    }
}
