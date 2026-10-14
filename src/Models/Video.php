<?php

namespace Ogunsakin01\VideoDemo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Ai\Usage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\Recorder;

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
            'stop_requested' => 'boolean',
        ];
    }

    /**
     * Perform any actions required after the model boots.
     */
    protected static function booted(): void
    {
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
        $this->status = $status;
        $message ? $this->addLog($message) : $this->save();
    }

    /**
     * Add the tokens and cost of an AI request to the video's totals.
     */
    public function addUsage(Usage $usage): void
    {
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
        return $this->workPath($kind === 'video' ? "video-$device.mp4" : "poster-$device.jpg");
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
        return (float) ($this->max_cost ?: config('video-demo.limits.max_cost_per_video', 2.0));
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
