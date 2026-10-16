<?php

namespace Ogunsakin01\VideoDemo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Verifier;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Throwable;

/**
 * Checks that a video's plan still works, for the studio's "Check it now".
 */
class VerifyVideo implements ShouldQueue
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
     * Create a new job instance.
     */
    public function __construct(public Video $video)
    {
        $this->onConnection(VideoDemoServiceProvider::queueConnection());
        $this->onQueue(VideoDemoServiceProvider::queueName());
    }

    /**
     * Execute the job.
     */
    public function handle(Verifier $verifier): void
    {
        $verifier->verify($this->video);
    }

    /**
     * Handle a job that failed outside handle(), such as a timeout.
     */
    public function failed(?Throwable $e): void
    {
        $video = $this->video->fresh();
        if ($video && ($video->verify_result['status'] ?? null) === 'working') {
            $video->forceFill(['verified_at' => now(), 'verify_result' => ['status' => 'done', 'ok' => false, 'error' => $e?->getMessage() ?: 'The check stopped before it finished.']])->save();
        }
    }
}
