<?php

namespace Ogunsakin01\VideoDemo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\ComponentAttributeBag;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Placer;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Devices;
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
     * The stages, in order, with what the panel says during each.
     */
    public const STAGES = [
        'data' => 'Copying the sample data',
        'pages' => 'Opening the video\'s pages',
        'picking' => 'The AI is picking spots',
        'pictures' => 'Checking each spot fits, and taking pictures',
    ];

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
        $costBefore = $video->cost;
        $sandbox = null;

        try {
            if ($video->sample_data) {
                $this->stage($video, 'data');
                $sandbox = Sandbox::create($video);
                $sandbox->prepare($video->record_as);
                if ($video->sample_records) {
                    $sandbox->seed($video->sample_records);
                }
                $recorder->sandbox = $sandbox;
            }

            $this->stage($video, 'pages');
            $map = $recorder->mapPages(static::paths($video), $video->record_as);
            $pages = array_values(array_filter($map['pages'], fn ($p) => $p['sections'] !== []));
            if ($pages === []) {
                throw new \RuntimeException('None of the video\'s pages could be opened'.($map['failed'] ? ': '.$map['failed'][0]['path'].' gave '.$map['failed'][0]['reason'] : '').'.');
            }

            $this->stage($video, 'picking');
            [$spots, $note] = $this->choose($video, $pages, $placer);
            if ($spots === []) {
                throw new \RuntimeException('No section of the video\'s pages could hold it.');
            }

            $this->stage($video, 'pictures');
            $dir = Storage::disk(config('video-demo.storage.work_disk'))->path($video->workPath('spots'));
            File::deleteDirectory($dir);
            $checked = $recorder->shootSpots(
                array_map(fn ($s) => ['path' => $s['path'], 'selector' => $s['section']['selector'], 'positions' => static::positions($s)], $spots),
                $this->playerHtml($video),
                $dir,
                ['desktop', 'mobile'],
                $video->record_as,
            );

            $tag = static::tag($video);
            $items = [];
            foreach ($spots as $i => $spot) {
                $result = $checked[$i] ?? null;
                if (! $result || ! $result['found'] || $result['shots'] === []) {
                    continue;
                }
                $section = $spot['section'];
                $items[] = [
                    'path' => $spot['path'],
                    'label' => $spot['label'],
                    'why' => $spot['why'],
                    'position' => $result['position'],
                    'heading' => $section['heading'],
                    'selector' => $section['selector'],
                    'fits' => $result['ok'],
                    'problems' => $result['problems'],
                    'shots' => array_map(fn ($states) => array_map('basename', $states), $result['shots']),
                    'code' => ViewFinder::placement($section['heading'] ?: '', $spot['path'], $section['tag'] ?? 'div', $section['classes'] ?? [], $result['position'], $tag),
                ];
            }

            if ($items === []) {
                throw new \RuntimeException('None of the suggested sections could be found on the page again, so nothing could be shown. Try again.');
            }

            $video->update(['spots' => ['status' => 'ready', 'at' => $started, 'note' => $note, 'items' => $items, 'cost' => round($video->cost - $costBefore, 6)]]);
        } catch (Throwable $e) {
            $video->update(['spots' => ['status' => 'failed', 'at' => $started, 'error' => $e->getMessage(), 'items' => [], 'cost' => round($video->cost - $costBefore, 6)]]);
        } finally {
            $sandbox?->delete();
            $recorder->sandbox = null;
        }
    }

    /**
     * Get the tag to paste for the video: its player, minimised until opened.
     */
    public static function tag(Video $video): string
    {
        return '<x-video-demo::player slug="'.$video->slug.'"'.($video->visibility === 'private' ? ' area="private"' : '').' collapsible />';
    }

    /**
     * Get the positions to try for a spot, the one picked first. Next to a
     * whole page's wrapper the player would sit outside the page's margins,
     * so it only goes inside one.
     *
     * @return list<string>
     */
    protected static function positions(array $spot): array
    {
        if (in_array($spot['section']['kind'], ['main', 'header', 'footer', 'nav'], true)) {
            return ['top'];
        }

        return array_values(array_unique([$spot['position'], 'top', 'before', 'after']));
    }

    /**
     * Render the player as the app will show it, with the video's poster, so
     * the screenshots show exactly what pasting the tag gives.
     */
    protected function playerHtml(Video $video): string
    {
        $device = $video->primaryDevice();
        $disk = Storage::disk(config('video-demo.storage.work_disk'));
        $poster = $disk->exists($video->workFile($device, 'poster'))
            ? 'data:image/jpeg;base64,'.base64_encode((string) $disk->get($video->workFile($device, 'poster')))
            : '';
        $screen = Devices::get($device);
        $source = ['video' => '', 'poster' => $poster, 'subtitles' => null, 'width' => $screen['width'], 'height' => $screen['height']];

        return view('video-demo::partials.collapsible-player', [
            'slug' => $video->slug,
            'title' => $video->title ?: ($video->plan['title'] ?? 'How-to video'),
            'seconds' => (float) ($video->duration_seconds ?? 0),
            'open' => false,
            'attributes' => new ComponentAttributeBag,
            'video' => ['title' => $video->title, 'language' => $video->languageCode()],
            'sources' => [$device => $source],
            'first' => $source,
            'device' => null,
        ])->render();
    }

    /**
     * Note the stage the job has reached, for the panel's progress bar.
     */
    protected function stage(Video $video, string $stage): void
    {
        $video->update(['spots' => [...($video->spots ?? []), 'status' => 'working', 'stage' => $stage, 'stage_at' => now()->toIso8601String()]]);
    }

    /**
     * Get how far along finding spots is, and how long it has left, from the
     * stage it is in and how long that stage usually takes. Null when it
     * isn't running.
     *
     * @return ?array{percent: int, remaining: int, stage: string}
     */
    public static function progress(Video $video): ?array
    {
        $spots = $video->spots ?? [];
        if (($spots['status'] ?? null) !== 'working') {
            return null;
        }

        $expected = static::expected($video);
        $stage = $spots['stage'] ?? null;
        if ($stage === null || ! isset($expected[$stage])) {
            return ['percent' => 0, 'remaining' => (int) array_sum($expected), 'stage' => 'Waiting for a queue worker'];
        }

        $elapsed = max(0, (int) Carbon::parse($spots['stage_at'] ?? 'now')->diffInSeconds(now()));
        $keys = array_keys($expected);
        $at = array_search($stage, $keys, true);
        $done = array_sum(array_slice($expected, 0, $at));
        $now = $expected[$stage];
        $total = max(1, array_sum($expected));
        $percent = (int) round(100 * ($done + $now * min(0.95, $elapsed / max(1, $now))) / $total);
        $remaining = max(3, $now - $elapsed) + (int) array_sum(array_slice($expected, $at + 1));

        return ['percent' => min(99, $percent), 'remaining' => $remaining, 'stage' => self::STAGES[$stage]];
    }

    /**
     * Get how long each stage usually takes for this video, in seconds.
     *
     * @return array<string, int>
     */
    protected static function expected(Video $video): array
    {
        return array_filter([
            'data' => $video->sample_data ? 25 : 0,
            'pages' => 6 + 5 * count(static::paths($video)),
            'picking' => Drivers::anyKey() ? 15 : 1,
            'pictures' => 10 + 3 * 2 * 6,
        ]);
    }

    /**
     * Get the pages to look at: the ones the video opens, in order, starting
     * with its start page.
     *
     * @return list<string>
     */
    protected static function paths(Video $video): array
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
