<?php

namespace Ogunsakin01\VideoDemo\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Throwable;

/**
 * Knows whether a queue worker is running for the package's queue, so the
 * studio can say when videos would wait forever. Workers leave a heartbeat
 * in the cache each time they look for a job, and while they make one.
 */
class Worker
{
    /**
     * The cache key of the last heartbeat, a Unix time.
     */
    public const SEEN = 'video-demo:worker-seen';

    /**
     * The cache key set while a worker makes a video, a Unix time.
     */
    public const BUSY = 'video-demo:worker-busy';

    /**
     * A worker not heard from in this many seconds is taken to be stopped. An
     * idle worker checks for jobs every few seconds.
     */
    public const STALE = 90;

    /**
     * Listen for the package's queue workers.
     */
    public static function listen(Dispatcher $events): void
    {
        $events->listen(Looping::class, function (Looping $e) {
            if (static::ours($e->connectionName, (string) $e->queue)) {
                static::beat();
            }
        });
        $events->listen(JobProcessing::class, function (JobProcessing $e) {
            if (static::ours($e->connectionName, (string) $e->job->getQueue())) {
                static::beat();
                static::remember(self::BUSY, time());
            }
        });
        $events->listen([JobProcessed::class, JobFailed::class], function (JobProcessed|JobFailed $e) {
            if (static::ours($e->connectionName, (string) $e->job->getQueue())) {
                static::beat();
                static::forget(self::BUSY);
            }
        });
    }

    /**
     * Determine if a worker is running: one looked for a job lately, or is in
     * the middle of one. Null when this can't be known, as with the array
     * cache, which a worker doesn't share with the studio.
     */
    public static function running(): ?bool
    {
        if (config('cache.default') === 'array' || config('cache.stores.'.config('cache.default').'.driver') === 'array') {
            return null;
        }

        try {
            $seen = (int) Cache::get(self::SEEN, 0);
            $busy = (int) Cache::get(self::BUSY, 0);
        } catch (Throwable) {
            return null;
        }

        // A video can take half an hour; a worker busy with one doesn't look for jobs meanwhile.
        return time() - $seen < self::STALE || ($busy > 0 && time() - $busy < 3600);
    }

    /**
     * Get a warning when no worker is running, for the studio's pages.
     */
    public static function warning(): ?string
    {
        if (VideoDemoServiceProvider::queueWarning() !== null || static::running() !== false) {
            return null;
        }

        return 'No queue worker is running, so new videos wait in the queue and nothing is made. Start one in a terminal: php artisan video-demo:work';
    }

    /**
     * Note that a worker was just seen.
     */
    public static function beat(): void
    {
        static::remember(self::SEEN, time());
    }

    /**
     * Determine if a worker works the package's queue. Any connection counts:
     * the package's own one shares your default connection's jobs.
     */
    protected static function ours(?string $connection, string $queues): bool
    {
        return in_array(VideoDemoServiceProvider::queueName(), explode(',', $queues), true);
    }

    protected static function remember(string $key, int $value): void
    {
        try {
            Cache::put($key, $value, 3600);
        } catch (Throwable) {
            // No cache to leave it in; the studio then can't tell.
        }
    }

    protected static function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
        }
    }
}
