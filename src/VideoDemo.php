<?php

namespace Ogunsakin01\VideoDemo;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\CostEstimator;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Ai\Suggester;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Jobs\TranslateVideo;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoIdea;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\Exporter;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\RecordAs;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\Verifier;
use Ogunsakin01\VideoDemo\Studio\Embeds;
use Ogunsakin01\VideoDemo\Studio\Pages;
use Ogunsakin01\VideoDemo\Support\Language;
use Ogunsakin01\VideoDemo\Support\Routes;
use Ogunsakin01\VideoDemo\Voice\Music;
use Ogunsakin01\VideoDemo\Voice\Voices;
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
     * as "admin", "guest", or a user id or email), sample_data (false records
     * on the app's own local data instead of a temporary sample database),
     * voice (such as "kokoro:af_heart"; see voices()), music (a track's key;
     * see music()), music_volume (0 to 1), language (such as "fr" or "pt-BR";
     * the app's pages' language when left out) and queue (false makes it now,
     * in this process). Throws InvalidArgumentException for a bad option.
     *
     * @param  array{title?: string, start?: string, length?: int, style?: string, devices?: list<string>, model?: string, effort?: string, private?: bool, max_cost?: float, max_steps?: int, speed?: float, as?: string, sample_data?: bool, voice?: ?string, music?: ?string, music_volume?: float, language?: ?string, queue?: bool}  $options
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
        if (! in_array($effort, Pricing::EFFORTS, true)) {
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

        // A style's own voice and music are the defaults, then the config's.
        $voice = array_key_exists('voice', $options) ? $options['voice'] : ($style->voice ?? config('video-demo.voice.default'));
        if ($voice && ! Voices::available($voice)) {
            throw new InvalidArgumentException("The voice [$voice] isn't available here. Choose one of: ".(implode(', ', array_keys(Voices::all())) ?: 'none (run php artisan video-demo:install-voice)').'.');
        }
        $music = array_key_exists('music', $options) ? $options['music'] : ($style->music ?? config('video-demo.music.default'));
        // A style's or the config's track that isn't here, such as a built-in one with those turned off, is left out.
        if ($music && ! Music::find($music) && ! array_key_exists('music', $options)) {
            $music = null;
        }
        if ($music && ! Music::find($music)) {
            throw new InvalidArgumentException("There is no music track [$music]. Choose one of: ".(implode(', ', array_keys(Music::options())) ?: 'none (add tracks on the Styles page)').'.');
        }
        $volume = $options['music_volume'] ?? null;
        if ($volume !== null && (! is_numeric($volume) || $volume < 0 || $volume > 1)) {
            throw new InvalidArgumentException('The music volume must be a number from 0 to 1, such as 0.35.');
        }

        $language = null;
        if (($options['language'] ?? null) !== null && $options['language'] !== '') {
            $language = Language::normalize($options['language']) ?? throw new InvalidArgumentException("[{$options['language']}] isn't a language code. Use one such as fr, es or pt-BR.");
        }
        if ($voice && $language && ! Voices::speaks($voice, $language)) {
            throw new InvalidArgumentException('The voice ['.$voice.'] doesn\'t speak '.Language::name($language).'. Choose one that does, or no voice.');
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
            'sample_data' => (bool) ($options['sample_data'] ?? config('video-demo.sample_data.default', true)),
            'voice' => $voice ?: null,
            'music' => $music ?: null,
            'music_volume' => $volume !== null ? (float) $volume : null,
            'language' => $language,
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
     * Make a video again: with a new plan, or by recording its plan again at
     * no AI cost. $sampleData switches recording on sample data on or off;
     * a switch always gets a new plan.
     */
    public function retry(Video $video, bool $newPlan = true, ?bool $sampleData = null): Video
    {
        if ($video->status->isWorking()) {
            throw new RuntimeException('This video is still being made.');
        }

        $switch = $sampleData !== null && $sampleData !== $video->sample_data;
        $replan = $newPlan || $switch || empty($video->plan['steps']);
        $video->update(['status' => VideoStatus::Queued, 'error' => null, 'stop_requested' => false, ...($switch ? ['sample_data' => $sampleData] : [])]);
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
     * Make a video in another language from this one's plan, as a video of
     * its own, linked to it: the AI translates the titles, captions,
     * chapters and voice-over (about a cent), the steps are checked with the
     * app in that language, and the plan is recorded without planning again.
     * The player and library show it to viewers in that language.
     *
     * Options: voice (one that speaks the language; the original's when it
     * does, else none) and queue (false makes it now, in this process).
     * Throws InvalidArgumentException for a bad language or voice.
     *
     * @param  array{voice?: ?string, queue?: bool}  $options
     */
    public function translate(Video $video, string $language, array $options = []): Video
    {
        if ($video->source_video_id) {
            throw new InvalidArgumentException('This video is itself a translation. Translate the original instead.');
        }
        if ($video->status->isWorking() || empty($video->plan['steps'])) {
            throw new InvalidArgumentException('Only a video with a finished plan can be translated.');
        }
        $code = Language::normalize($language) ?? throw new InvalidArgumentException("[$language] isn't a language code. Use one such as fr, es or pt-BR.");
        if (Language::same($code, $video->languageCode())) {
            throw new InvalidArgumentException('The video is already in '.Language::name($video->languageCode()).'.');
        }
        if ($existing = $video->translations()->get()->first(fn (Video $t) => Language::same($t->languageCode(), $code))) {
            throw new InvalidArgumentException('There is already a '.Language::name($existing->languageCode())." version: {$existing->slug}. Make it again from its own page.");
        }

        $voice = array_key_exists('voice', $options) ? ($options['voice'] ?: null) : ($video->voice && Voices::speaks($video->voice, $code) ? $video->voice : null);
        if ($voice && ! Voices::available($voice)) {
            throw new InvalidArgumentException("The voice [$voice] isn't available here.");
        }
        if ($voice && ! Voices::speaks($voice, $code)) {
            throw new InvalidArgumentException(Voices::label($voice)." doesn't speak ".Language::name($code).'. Choose one that does, or no voice.');
        }

        $translation = Video::create([
            // Settings left empty take the columns' defaults.
            ...array_filter($video->only(['prompt', 'start_path', 'target_seconds', 'model', 'effort', 'visibility', 'style', 'devices', 'max_cost', 'max_steps', 'speed', 'record_as', 'record_as_label', 'sample_data', 'sample_records', 'music', 'music_volume']), fn ($v) => $v !== null),
            'slug' => Video::uniqueSlug($video->slug.'-'.strtolower($code)),
            'title' => null,
            'plan' => $video->plan,
            'language' => $code,
            'voice' => $voice,
            'source_video_id' => $video->id,
            'status' => VideoStatus::Queued,
        ]);
        $translation->addLog(sprintf('Queued: the %s version of "%s"', Language::name($code), $video->title ?: $video->slug));

        if ($options['queue'] ?? true) {
            TranslateVideo::dispatch($translation);
        } else {
            TranslateVideo::dispatchSync($translation);
        }

        return $translation->fresh() ?? $translation;
    }

    /**
     * Replace a video's plan with one edited by hand, such as a caption
     * reworded or a step removed. Nothing is recorded: call rerecord() to
     * record it again, at no AI cost. Throws InvalidArgumentException, listing
     * the problems, when the plan can't be recorded.
     *
     * Each step has an action (see Plan::ACTIONS) and the fields it needs:
     * url for goto, target for click, hover, check, type and select, value
     * for type, select and press, title for caption and card. Any step may
     * have text, ms, chapter, say (its voice-over line) and only_on.
     *
     * @param  array{title?: ?string, summary?: ?string, steps: array<int, array<string, mixed>>}  $plan
     */
    public function updatePlan(Video $video, array $plan): Video
    {
        if ($video->status->isWorking()) {
            throw new RuntimeException('This video is still being made.');
        }

        $edited = Plan::fromArray([
            'title' => $plan['title'] ?? $video->plan['title'] ?? '',
            'summary' => $plan['summary'] ?? $video->plan['summary'] ?? '',
            'steps' => array_map(Plan::clean(...), array_values($plan['steps'])),
        ]);
        if ($problems = $edited->problems($video->max_steps)) {
            throw new InvalidArgumentException(implode(' ', $problems));
        }

        $video->update(['plan' => [...$edited->toArray(), 'chapters' => $video->plan['chapters'] ?? []], 'plan_edited_at' => now()]);
        $video->addLog(sprintf('Plan edited by hand: %d steps', count($edited->steps)));

        return $video;
    }

    /**
     * Check that a video's plan still works in the app, without recording it
     * and at no AI cost. The result is saved on the video; ok is false, with
     * the step, device and error, when it no longer works.
     *
     * @return array{status: string, ok: bool, checked: list<string>, device: ?string, step: ?int, action: ?string, error: ?string, url: ?string, issues: list<array<string, mixed>>, screenshot: bool}
     */
    public function verify(Video $video): array
    {
        if ($video->status->isWorking()) {
            throw new RuntimeException('This video is still being made.');
        }

        return app(Verifier::class)->verify($video);
    }

    /**
     * Make a copy of a finished video in another format, now, and get the
     * file's full path: "gif" (for READMEs, changelogs and emails),
     * "square" or "vertical" (9:16 MP4s with the title above the app, for
     * social posts). $device picks the version it is made from. Costs no AI.
     */
    public function export(Video $video, string $format, ?string $device = null): string
    {
        if ($video->status->isWorking()) {
            throw new RuntimeException('This video is still being made.');
        }

        $file = app(Exporter::class)->export($video, $format, $device);
        $video->forceFill(['exports' => [...($video->exports ?? []), $format => [
            'status' => 'ready', 'device' => $device ?? $video->primaryDevice(), 'version' => $video->version, 'at' => now()->toIso8601String(),
            'bytes' => Storage::disk(config('video-demo.storage.work_disk'))->size($file),
        ]]])->save();

        return Storage::disk(config('video-demo.storage.work_disk'))->path($file);
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
     * Delete a video: its recordings, and its published files if it is in
     * the library. Pages that embed it then show nothing; see embeds().
     */
    public function delete(Video $video): void
    {
        if ($video->status->isWorking()) {
            throw new RuntimeException('This video is still being made. Stop it first.');
        }

        if ($video->isPublished()) {
            $this->publisher->unpublish($video);
        }
        // The idea it was made from can be made again.
        VideoIdea::where('video_id', $video->id)->update(['video_id' => null]);
        $video->delete();
        Storage::disk(config('video-demo.storage.work_disk'))->deleteDirectory($video->workPath());
    }

    /**
     * Find where the app's code shows a video, by file and line.
     *
     * @return list<array{file: string, line: int, text: string}>
     */
    public function embeds(Video $video): array
    {
        return Embeds::find([$video->slug])[$video->slug] ?? [];
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
     * Get the published videos that show a page: by its route's name (such
     * as "jobs.create"), its path (such as "/jobs/3"), or the current
     * request's route when left out. Videos that start there come first.
     *
     * @return list<array<string, mixed>>
     */
    public function forPage(?string $route = null, string $area = 'public'): array
    {
        if ($route !== null && str_starts_with($route, '/')) {
            $match = Routes::match($route);

            return $this->manifest->forRoute($match?->getName(), $match?->uri() ?? $route, $area);
        }

        return $route !== null
            ? $this->manifest->forRoute($route, null, $area)
            : $this->manifest->forRoute(request()->route()?->getName(), request()->route()?->uri(), $area);
    }

    /**
     * Get the address of a published video's MP4, poster or subtitles
     * ("video", "poster" or "subtitles"), or null when it has none.
     *
     * @param  array<string, mixed>  $entry  A video from library() or search().
     */
    public function url(array $entry, string $kind = 'video', ?string $device = null): ?string
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
     * Get the voices a video can be narrated in on this machine, key => label.
     *
     * @return array<string, string>
     */
    public function voices(): array
    {
        return Voices::all();
    }

    /**
     * Get the background music tracks, key => label.
     *
     * @return array<string, string>
     */
    public function music(): array
    {
        return Music::options();
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
