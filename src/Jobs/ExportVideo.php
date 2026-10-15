<?php

namespace Ogunsakin01\VideoDemo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Exporter;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Throwable;

/**
 * Makes a copy of a finished video as a GIF, or as a square or vertical MP4,
 * for the studio's Downloads.
 */
class ExportVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 2400;

    /**
     * Create a new job instance.
     */
    public function __construct(public Video $video, public string $format, public ?string $device = null)
    {
        $this->onConnection(config('video-demo.queue.connection') ?: VideoDemoServiceProvider::QUEUE_CONNECTION);
        $this->onQueue(config('video-demo.queue.name'));
    }

    /**
     * Execute the job.
     */
    public function handle(Exporter $exporter): void
    {
        $video = $this->video;
        $device = $this->device ?? $video->primaryDevice();

        try {
            $file = $exporter->export($video, $this->format, $device);
            $this->save(['status' => 'ready', 'device' => $device, 'version' => $video->version, 'bytes' => Storage::disk(config('video-demo.storage.work_disk'))->size($file)]);
        } catch (Throwable $e) {
            $this->save(['status' => 'failed', 'device' => $device, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Save the state of this format's copy, keeping the others'.
     *
     * @param  array<string, mixed>  $state
     */
    protected function save(array $state): void
    {
        $video = $this->video->fresh() ?? $this->video;
        $video->forceFill(['exports' => [...($video->exports ?? []), $this->format => [...$state, 'at' => now()->toIso8601String()]]])->save();
    }

    /**
     * Handle a job that failed outside handle(), such as a timeout.
     */
    public function failed(?Throwable $e): void
    {
        $video = $this->video->fresh();
        if ($video && ($video->exports[$this->format]['status'] ?? null) === 'working') {
            $this->save(['status' => 'failed', 'error' => $e?->getMessage() ?: 'It stopped before it finished.']);
        }
    }
}
