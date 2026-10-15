<?php

namespace Ogunsakin01\VideoDemo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Ai\Usage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Events\VideoStatusChanged;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Support\Language;
use Ogunsakin01\VideoDemo\Voice\Voices;

/**
 * A video made in the studio, with its plan, recordings, AI usage and log.
 *
 * @property int $id
 * @property string $slug
 * @property ?string $title
 * @property string $prompt
 * @property ?string $start_path
 * @property int $target_seconds
 * @property string $model
 * @property string $effort
 * @property string $visibility
 * @property VideoStatus $status
 * @property ?array $plan
 * @property ?array $style
 * @property ?list<string> $devices
 * @property ?array<string, array{seconds: float, chapters: array}> $recordings
 * @property ?array $log
 * @property ?string $error
 * @property int $input_tokens
 * @property int $cache_write_tokens
 * @property int $cache_read_tokens
 * @property int $output_tokens
 * @property float $cost
 * @property ?float $estimate_low
 * @property ?float $estimate_high
 * @property ?float $duration_seconds
 * @property int $version
 * @property ?Carbon $published_at
 * @property ?float $max_cost
 * @property ?float $speed
 * @property ?int $max_steps
 * @property ?string $record_as
 * @property ?string $record_as_label
 * @property ?list<array{path: string, problem: string, status: ?int, url: ?string, detail: ?string}> $issues
 * @property bool $stop_requested
 * @property ?Carbon $status_at
 * @property ?array<string, mixed> $error_details
 * @property ?Carbon $updated_at
 * @property bool $sample_data
 * @property ?list<array<string, mixed>> $sample_records
 * @property ?string $voice
 * @property ?string $music
 * @property ?float $music_volume
 * @property ?string $language
 * @property ?string $page_language
 */
class Video extends Model
{
    protected $table = 'video_demo_videos';

    protected $guarded = ['id'];

    protected $attributes = ['log' => '[]'];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'plan' => 'array',
            'style' => 'array',
            'devices' => 'array',
            'recordings' => 'array',
            'log' => 'array',
            'cost' => 'float',
            'estimate_low' => 'float',
            'estimate_high' => 'float',
            'duration_seconds' => 'float',
            'published_at' => 'datetime',
            'max_cost' => 'float',
            'speed' => 'float',
            'issues' => 'array',
            'error_details' => 'array',
            'stop_requested' => 'boolean',
            'sample_data' => 'boolean',
            'sample_records' => 'array',
            'music_volume' => 'float',
            'status_at' => 'datetime',
        ];
    }

    /**
     * Perform any actions required after the model boots.
     */
    protected static function booted(): void
    {
        // The full error goes with the error: clearing one clears both.
        static::saving(function (Video $video) {
            if ($video->error === null) {
                $video->error_details = null;
            }
        });

        static::creating(function (Video $video) {
            $video->slug ??= static::uniqueSlug($video->title ?: Str::words($video->prompt, 8, ''));
        });
    }

    /**
     * Get a slug for the title that no other video uses.
     */
    public static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'video';
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }

    /**
     * Add a message to the video's log.
     *
     * The job and the studio both write to the log, so this starts from the
     * saved log rather than the copy in memory.
     */
    public function addLog(string $message): void
    {
        $saved = $this->exists ? static::whereKey($this->id)->first(['id', 'log'])?->log : null;
        $this->log = [...($saved ?? $this->log ?? []), ['at' => now()->toIso8601String(), 'message' => $message]];
        $this->save();
    }

    /**
     * Move the video to a status, logging the message if one is given.
     */
    public function moveTo(VideoStatus $status, ?string $message = null): void
    {
        $from = $this->getOriginal('status');
        $this->status = $status;
        if ($from !== $status) {
            // For the progress bar: how long it has been in this stage.
            $this->status_at = now();
        }
        $message ? $this->addLog($message) : $this->save();

        if ($from !== $status) {
            VideoStatusChanged::dispatch($this, $from instanceof VideoStatus ? $from : VideoStatus::tryFrom((string) $from), $status);
        }
    }

    /**
     * Add the tokens and cost of an AI request to the video's totals, and to
     * the total spent: plan, improve, narrate or voice.
     */
    public function addUsage(Usage $usage, string $kind = 'plan'): void
    {
        Spend::record($kind, $usage->cost, $this->id, $kind === 'voice' ? $this->voice : $this->model);
        $this->input_tokens += $usage->input;
        $this->cache_write_tokens += $usage->cacheWrite;
        $this->cache_read_tokens += $usage->cacheRead;
        $this->output_tokens += $usage->output;
        $this->cost = round($this->cost + $usage->cost, 6);
        $this->save();
    }

    /**
     * Get the folder for this video's working files on the work disk, or a file
     * in it.
     */
    public function workPath(string $file = ''): string
    {
        return rtrim(config('video-demo.storage.work_path'), '/')."/{$this->id}".($file !== '' ? "/$file" : '');
    }

    /**
     * Get the language the video is written and spoken in: the one chosen,
     * else the one found on the app's pages, else the app's locale.
     */
    public function languageCode(): string
    {
        return Language::normalize($this->language) ?? Language::normalize($this->page_language) ?? Language::app();
    }

    /**
     * Determine if the video has a voice-over: a voice is chosen and it
     * speaks the video's language.
     */
    public function hasVoiceOver(): bool
    {
        return (bool) $this->voice && Voices::speaks($this->voice, $this->languageCode());
    }

    /**
     * Get the versions this video is recorded in.
     */
    public function deviceList(): array
    {
        return $this->devices ?: Devices::defaults();
    }

    /**
     * Get the version shown first and used for the poster.
     */
    public function primaryDevice(): string
    {
        return Devices::primary($this->recordedDevices() ?: $this->deviceList());
    }

    /**
     * Get the versions that have a finished recording.
     */
    public function recordedDevices(): array
    {
        return array_keys($this->recordings ?? []);
    }

    /**
     * Get the path of a version's MP4 or poster on the work disk.
     */
    public function workFile(string $device, string $kind = 'video'): string
    {
        return $this->workPath(match ($kind) {
            'poster' => "poster-$device.jpg",
            'subtitles' => "subtitles-$device.vtt",
            default => "video-$device.mp4",
        });
    }

    /**
     * Determine if a version, the primary one by default, has a recording on the
     * work disk.
     */
    public function hasRecording(?string $device = null): bool
    {
        $device ??= $this->primaryDevice();

        return isset($this->recordings[$device])
            && Storage::disk(config('video-demo.storage.work_disk'))->exists($this->workFile($device));
    }

    /**
     * Get how many times faster this video plays than it was recorded.
     */
    public function playbackSpeed(): float
    {
        return Recorder::speed($this->speed);
    }

    /**
     * Get the most this video may spend on AI: its own limit, else the configured one.
     */
    public function spendLimit(): float
    {
        return (float) ($this->max_cost ?: config('video-demo.limits.max_cost_per_video', 0.5));
    }

    /**
     * Determine if someone pressed Stop.
     *
     * This reads the database, since the job runs in another process.
     */
    public function stopRequested(): bool
    {
        return (bool) static::whereKey($this->id)->value('stop_requested');
    }

    /**
     * Determine if the video is published to the library.
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
