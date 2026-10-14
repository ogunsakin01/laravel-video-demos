<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Throwable;

/**
 * Records published videos again from their stored plans and republishes them,
 * after the app's look changes. Claude is not asked, so it costs nothing.
 */
class RefreshCommand extends Command
{
    protected $signature = 'video-demo:refresh {slug?* : Only these videos}';

    protected $description = 'Record published videos again from their saved plans and republish them';

    public function handle(Publisher $publisher): int
    {
        $videos = Video::whereNotNull('published_at')
            ->when($this->argument('slug'), fn ($q, $slugs) => $q->whereIn('slug', $slugs))
            ->get();

        if ($videos->isEmpty()) {
            $this->components->info('No published videos to refresh.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($videos as $video) {
            $this->components->task($video->title, function () use ($video, $publisher, &$failed) {
                try {
                    $video->update(['status' => VideoStatus::Queued, 'error' => null]);
                    GenerateVideo::dispatchSync($video, false);
                    $video->refresh();
                    if ($video->status !== VideoStatus::Ready) {
                        throw new \RuntimeException($video->error ?? 'Recording failed.');
                    }
                    $publisher->publish($video);

                    return true;
                } catch (Throwable $e) {
                    $failed++;
                    $this->components->error("{$video->title}: {$e->getMessage()} Open it in the studio and ask Claude for a new plan.");

                    return false;
                }
            });
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
