<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Throwable;

/**
 * Publishes a finished video to the library, or takes it down.
 */
class PublishCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:publish
        {video : The video\'s id or slug}
        {--unpublish : Take it out of the library instead}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish a finished video to the library, or unpublish it';

    /**
     * Execute the console command.
     */
    public function handle(Publisher $publisher): int
    {
        $key = (string) $this->argument('video');
        $video = Video::where('slug', $key)->when(ctype_digit($key), fn ($q) => $q->orWhere('id', (int) $key))->first();

        if (! $video) {
            $this->components->error("No video [$key]. See php artisan video-demo:list.");

            return self::FAILURE;
        }

        if ($this->option('unpublish')) {
            $publisher->unpublish($video);
            $this->components->info("\"{$video->title}\" is no longer in the library.");

            return self::SUCCESS;
        }

        if ($video->status !== VideoStatus::Ready) {
            $this->components->error("\"{$video->title}\" is {$video->status->value}, not ready, so it can't be published yet.");

            return self::FAILURE;
        }

        try {
            $publisher->publish($video);
        } catch (Throwable $e) {
            $this->components->error('Could not publish: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("\"{$video->title}\" is in the library.");
        if (config('video-demo.library.public.enabled') && $video->visibility === 'public') {
            $this->components->twoColumnDetail('Library', route('video-demo.public.show', $video->slug));
        }

        return self::SUCCESS;
    }
}
