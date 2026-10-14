<?php

namespace Ogunsakin01\VideoDemo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Ai\Usage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Recording\Devices;

/**
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
 * @property float $cost
 * @property ?float $estimate_low
 * @property ?float $estimate_high
 * @property ?float $duration_seconds
 * @property int $version
 * @property ?\Illuminate\Support\Carbon $published_at
 */
class Video extends Model
{
    protected $table = 'video_demo_videos';

    protected $guarded = ['id'];

    protected $attributes = ['log' => '[]'];

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
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Video $video) {
            $video->slug ??= static::uniqueSlug($video->title ?: Str::words($video->prompt, 8, ""));
        });
    }

    public static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'video';
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }

    public function addLog(string $message): void
    {
        $this->log = [...($this->log ?? []), ['at' => now()->toIso8601String(), 'message' => $message]];
        $this->save();
    }

    public function moveTo(VideoStatus $status, ?string $message = null): void
    {
        $this->status = $status;
        $message ? $this->addLog($message) : $this->save();
    }

    public function addUsage(Usage $usage): void
    {
        $this->input_tokens += $usage->input;
        $this->cache_write_tokens += $usage->cacheWrite;
        $this->cache_read_tokens += $usage->cacheRead;
        $this->output_tokens += $usage->output;
        $this->cost = round($this->cost + $usage->cost, 6);
        $this->save();
    }

    /** Folder for this video's working files on the work disk */
    public function workPath(string $file = ''): string
    {
        return rtrim(config('video-demo.storage.work_path'), '/')."/{$this->id}".($file !== '' ? "/$file" : '');
    }

    /** The versions this video is recorded in */
    public function deviceList(): array
    {
        return $this->devices ?: Devices::defaults();
    }

    public function primaryDevice(): string
    {
        return Devices::primary($this->recordedDevices() ?: $this->deviceList());
    }

    /** Versions with a finished recording */
    public function recordedDevices(): array
    {
        return array_keys($this->recordings ?? []);
    }

    /** A version's MP4 or poster on the work disk */
    public function workFile(string $device, string $kind = 'video'): string
    {
        return $this->workPath($kind === 'video' ? "video-$device.mp4" : "poster-$device.jpg");
    }

    public function hasRecording(?string $device = null): bool
    {
        $device ??= $this->primaryDevice();

        return isset($this->recordings[$device])
            && Storage::disk(config('video-demo.storage.work_disk'))->exists($this->workFile($device));
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
