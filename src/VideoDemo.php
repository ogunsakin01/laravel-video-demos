<?php

namespace Ogunsakin01\VideoDemo;

use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\CostEstimator;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Ai\Suggester;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoIdea;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\RecordAs;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Studio\Pages;
use RuntimeException;

/**
 * Everything the studio does, for apps that build their own screens or
 * automate video making. Use it through the VideoDemo facade.
 */
class VideoDemo
{
    /**
     * Create a new VideoDemo instance.
     */
    public function __construct(
        protected CostEstimator $estimator,
        protected CodeTools $code,
        protected Publisher $publisher,
        protected Manifest $manifest,
    ) {}

    /**
     * Create a video and queue it to be made.
     *
     * Options, all optional: title, start (a path), length (seconds), style
     * (a key or name), devices (list), model, effort, private (bool),
     * max_cost (dollars), max_steps, speed (0.25 to 4), as (a user type such
     * as "admin", "guest", or a user id or email) and queue (false makes it
     * now, in this process). Throws InvalidArgumentException for a bad option.
     *
     * @param  array{title?: string, start?: string, length?: int, style?: string, devices?: list<string>, model?: string, effort?: string, private?: bool, max_cost?: float, max_steps?: int, speed?: float, as?: string, queue?: bool}  $options
     */
    public function make(string $description, array $options = []): Video
    {
        $video = $this->create($description, $options);

        if ($options['queue'] ?? true) {
            GenerateVideo::dispatch($video);
        } else {
            try {
                GenerateVideo::dispatchSync($video);
            } catch (\Throwable) {
                // The job saves the error on the video.
            }
        }

        return $video->fresh() ?? $video;
    }

    /**
     * Create a video without starting it, after checking the options.
     *
     * @param  array<string, mixed>  $options
     */
    public function create(string $description, array $options = [], string $log = 'Queued'): Video
    {
        if (trim($description) === '') {
            throw new InvalidArgumentException('Describe what the video should show.');
        }

        $model = $options['model'] ?? Pricing::defaultModel();
        if (! Pricing::model($model)) {
            throw new InvalidArgumentException("Unknown model [$model]. Choose one of: ".implode(', ', array_keys(config('video-demo.ai.models'))).'.');
        }

        $effort = $options['effort'] ?? config('video-demo.ai.effort');
        if (! in_array($effort, ['low', 'medium', 'high', 'xhigh', 'max'], true)) {
            throw new InvalidArgumentException("Unknown effort [$effort]. Choose low, medium, high, xhigh or max.");
        }

        $devices = $options['devices'] ?? Devices::defaults();
        if ($unknown = array_diff($devices, Devices::keys())) {
            throw new InvalidArgumentException('Unknown versions: '.implode(', ', $unknown).'. Choose from: '.implode(', ', Devices::keys()).'.');
        }

        $style = $this->style($options['style'] ?? null);

        $seconds = (int) ($options['length'] ?? 60);
        $max = (int) config('video-demo.limits.max_seconds', 300);
        if ($seconds < 10 || $seconds > $max) {
            throw new InvalidArgumentException("The length must be between 10 and $max seconds.");
        }

        $start = $options['start'] ?? null;
        if ($start !== null && ! str_starts_with($start, '/')) {
            throw new InvalidArgumentException('The start page must be a path, such as /dashboard.');
        }

        $maxCost = $options['max_cost'] ?? null;
        if ($maxCost !== null && (! is_numeric($maxCost) || $maxCost <= 0)) {
            throw new InvalidArgumentException('The spending limit must be a number of dollars above 0, such as 0.50.');
        }

        $maxSteps = $options['max_steps'] ?? null;
        if ($maxSteps !== null && (! ctype_digit((string) $maxSteps) || $maxSteps < 3)) {
            throw new InvalidArgumentException('Max steps must be a whole number of at least 3.');
        }

        $speed = $options['speed'] ?? null;
        if ($speed !== null && (! is_numeric($speed) || $speed < 0.25 || $speed > 4)) {
            throw new InvalidArgumentException('The speed must be a number from 0.25 to 4, such as 1.5.');
        }

        $recordAs = $options['as'] ?? null;
        if ($recordAs !== null) {
            try {
                $recordAs = RecordAs::parse($recordAs);
            } catch (RuntimeException) {
                $types = implode(', ', array_keys(RecordAs::types()));
                throw new InvalidArgumentException("Unknown user type [$recordAs]. Choose guest".($types ? ", $types" : '').', or a user id or email.');
            }
        }

        $estimate = $this->estimate($model, $effort, $seconds);

        $video = Video::create([
            'title' => ($options['title'] ?? null) ?: null,
            'prompt' => $description,
            'start_path' => $start,
            'target_seconds' => $seconds,
            'model' => $model,
            'effort' => $effort,
            'visibility' => ($options['private'] ?? false) ? 'private' : 'public',
            'style' => $style->snapshot(),
            'devices' => array_values(array_intersect(Devices::keys(), $devices)),
            'max_cost' => $maxCost !== null ? (float) $maxCost : null,
            'max_steps' => $maxSteps !== null ? (int) $maxSteps : null,
            'speed' => $speed !== null ? (float) $speed : null,
            'record_as' => $recordAs,
            'record_as_label' => RecordAs::label($recordAs),
            'status' => VideoStatus::Queued,
            'estimate_low' => $estimate['low'],
            'estimate_high' => $estimate['high'],
        ]);
        $video->addLog($log);

        return $video;
    }

    /**
     * Get the estimated AI cost of a video, in US dollars.
     *
     * @return array{low: float, expected: float, high: float, turns: int, known: bool}
     */
    public function estimate(?string $model = null, ?string $effort = null, int $seconds = 60): array
    {
        return $this->estimator->estimate($model ?? Pricing::defaultModel(), $effort ?? config('video-demo.ai.effort'), $seconds, $this->code->routeCount());
    }

    /**
     * Stop a video being made: at once if queued, otherwise at its next safe point.
     */
    public function stop(Video $video): Video
    {
        if ($video->status === VideoStatus::Queued) {
            $video->update(['stop_requested' => true]);
            $video->moveTo(VideoStatus::Stopped, 'Stopped before it started.');
        } elseif ($video->status->isWorking()) {
            $video->update(['stop_requested' => true]);
            $video->addLog('Stopping…');
        }

        return $video;
    }

    /**
     * Make a video again: with a new plan, or by recording its plan again at no AI cost.
     */
    public function retry(Video $video, bool $newPlan = true): Video
    {
        if ($video->status->isWorking()) {
            throw new RuntimeException('This video is still being made.');
        }

        $replan = $newPlan || empty($video->plan['steps']);
        $video->update(['status' => VideoStatus::Queued, 'error' => null, 'stop_requested' => false]);
        $video->addLog($replan ? 'Trying again with a new plan' : 'Recording the same plan again');
        GenerateVideo::dispatch($video, replan: $replan);

        return $video;
    }

    /**
     * Record a video's plan again, at no AI cost, optionally for other versions or at another speed.
     *
     * @param  list<string>|null  $devices
     */
    public function rerecord(Video $video, ?array $devices = null, ?float $speed = null): Video
    {
        if (empty($video->plan['steps'])) {
            throw new RuntimeException('This video has no plan to record yet.');
        }

        $video->update(array_filter([
            'devices' => $devices !== null ? array_values(array_intersect(Devices::keys(), $devices)) : null,
            'speed' => $speed !== null ? Recorder::speed($speed) : null,
        ], fn ($v) => $v !== null));

        return $this->retry($video, newPlan: false);
    }

    /**
     * Publish a ready video to the library.
     */
    public function publish(Video $video): Video
    {
        $this->publisher->publish($video);

        return $video->fresh() ?? $video;
    }

    /**
     * Take a video out of the library.
     */
    public function unpublish(Video $video): Video
    {
        $this->publisher->unpublish($video);

        return $video->fresh() ?? $video;
    }

    /**
     * Get every video, newest first.
     *
     * @return Collection<int, Video>
     */
    public function videos(): Collection
    {
        return Video::query()->latest()->get();
    }

    /**
     * Find a video by its id or slug.
     */
    public function find(int|string $idOrSlug): ?Video
    {
        return is_int($idOrSlug) || ctype_digit($idOrSlug)
            ? Video::find((int) $idOrSlug)
            : Video::where('slug', $idOrSlug)->first();
    }

    /**
     * Get the published videos: "public" lists public ones, "private" all of them.
     *
     * @return list<array<string, mixed>>
     */
    public function library(string $area = 'public'): array
    {
        return $this->manifest->forArea($area);
    }

    /**
     * Search the published videos, best match first.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, string $area = 'public'): array
    {
        return $this->manifest->search($query, $area);
    }

    /**
     * Get the address of a published video's MP4 or poster.
     *
     * @param  array<string, mixed>  $entry  A video from library() or search().
     */
    public function url(array $entry, string $kind = 'video', ?string $device = null): string
    {
        return $this->manifest->url($entry, $kind, $device);
    }

    /**
     * Ask the AI model which videos to make first, and save the ideas.
     *
     * @return array<string, mixed>
     */
    public function suggest(?string $model = null, bool $replace = false): array
    {
        return app(Suggester::class)->suggest($model, $replace);
    }

    /**
     * Get the saved ideas, those not made yet first.
     *
     * @return Collection<int, VideoIdea>
     */
    public function ideas(): Collection
    {
        return VideoIdea::query()->orderByRaw('video_id is not null')->latest('id')->get();
    }

    /**
     * Get the styles a video can use.
     *
     * @return Collection<int, VideoStyle>
     */
    public function styles(): Collection
    {
        return VideoStyle::options();
    }

    /**
     * Get the types of user a video can be recorded as, value => label.
     *
     * @return array<string, string>
     */
    public function userTypes(): array
    {
        return RecordAs::options();
    }

    /**
     * Get the AI models that can be used, id => label.
     *
     * @return array<string, string>
     */
    public function models(): array
    {
        return Pricing::options();
    }

    /**
     * Get the pages a video can start on, as a type of user, with their headings.
     *
     * @return array{pages: list<array{path: string, title: string, name: ?string}>, skipped: int, checked_at: string}
     */
    public function pages(?string $as = null, bool $fresh = false): array
    {
        return app(Pages::class)->for($as, $fresh);
    }

    /**
     * Open pages in a browser and report the ones that don't work, at no AI cost.
     *
     * @param  list<string>  $paths
     * @return list<array{path: string, problem: string, status: ?int, url: ?string, detail: ?string}>
     */
    public function checkPages(array $paths, ?string $as = null): array
    {
        return app(Recorder::class)->checkPages($paths, $as);
    }

    /**
     * Find a style by its key or name, or the first style.
     */
    protected function style(?string $wanted): VideoStyle
    {
        $styles = VideoStyle::options();

        if (! $wanted) {
            return $styles->first() ?? throw new InvalidArgumentException('There are no styles. Add one on the studio\'s Styles page or in video-demo.styles.');
        }

        return $styles->first(fn (VideoStyle $s) => $s->key === $wanted || strtolower($s->name) === strtolower($wanted))
            ?? throw new InvalidArgumentException("Unknown style [$wanted]. Choose one of: ".$styles->pluck('key')->implode(', ').'.');
    }
}
