<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\VideoDemo;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Throwable;

/**
 * Makes a video from the command line the same way the studio does, for scripts and AI agents.
 * It waits for the video by default, so the result is known when the command ends.
 */
class GenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:generate
        {description : What the video should show, the way users would ask about it}
        {--title= : The video title (the AI suggests one when left out)}
        {--length=60 : Target length in seconds}
        {--style= : A style key or name, such as "ad" or "Quick tip" (the first style when left out)}
        {--devices= : Versions to record, comma separated: desktop, tv, tablet, mobile}
        {--model= : The AI model (the cheapest reliable one when left out)}
        {--effort= : low, medium, high, xhigh or max (Claude only)}
        {--start= : The path the video starts on, such as /dashboard}
        {--max-cost= : Spending limit in US dollars; the AI plans within it and stops before passing it}
        {--max-steps= : The most steps the plan may have}
        {--speed= : How much faster the video plays than it was recorded, from 0.25 to 4 (VIDEO_DEMO_SPEED when left out)}
        {--as= : Record as a type of user, such as "admin", "guest" for signed out, or a user id or email}
        {--sample-data : Record on sample data (the default, unless VIDEO_DEMO_SAMPLE_DATA=false)}
        {--own-data : Record on your own local data instead of sample data}
        {--voice= : A voice-over voice, such as kokoro:af_heart ("none" for no voice)}
        {--music= : The key of a background music track ("none" for no music)}
        {--private : List it in the private library only}
        {--publish : Publish it to the library when it is ready}
        {--queue : Queue it and return straight away, instead of waiting}
        {--json : Print the result as JSON, for scripts and AI agents}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Plan and record a how-to video of this app, like the studio does';

    /**
     * Execute the console command.
     */
    public function handle(Publisher $publisher): int
    {
        if (! VideoDemoServiceProvider::studioEnabled()) {
            return $this->refuse('The studio is disabled here. Videos are made where it is enabled, usually your local machine (VIDEO_DEMO_STUDIO=true).');
        }

        try {
            $video = $this->createVideo();
        } catch (\InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        if ($this->option('queue')) {
            GenerateVideo::dispatch($video);

            return $this->report($video, 'Queued. A queue worker will make it; check with video-demo:list.');
        }

        if (! $this->option('json')) {
            $this->components->info("Making \"{$video->title}\". This takes a few minutes.");
        }

        try {
            GenerateVideo::dispatchSync($video);
        } catch (Throwable) {
            // The job records the error on the video.
        }
        $video->refresh();

        if ($video->status === VideoStatus::Ready && $this->option('publish')) {
            $publisher->publish($video);
            $video->refresh();
        }

        return $this->report($video);
    }

    /**
     * Validate the options and create the queued video.
     *
     * @throws \InvalidArgumentException
     */
    protected function createVideo(): Video
    {
        $devices = $this->option('devices') ? array_map('trim', explode(',', $this->option('devices'))) : null;

        $video = app(VideoDemo::class)->create((string) $this->argument('description'), array_filter([
            'title' => $this->option('title') ?: null,
            'start' => $this->option('start'),
            'length' => (int) $this->option('length'),
            'style' => $this->option('style') ?: null,
            'devices' => $devices,
            'model' => $this->option('model') ?: null,
            'effort' => $this->option('effort') ?: null,
            'private' => (bool) $this->option('private'),
            'max_cost' => $this->option('max-cost'),
            'max_steps' => $this->option('max-steps'),
            'speed' => $this->option('speed'),
            'as' => $this->option('as') ?: null,
            'sample_data' => $this->option('own-data') ? false : ($this->option('sample-data') ?: null),
            // "none" is kept as an empty value, so it means no voice rather than the default.
            'voice' => $this->option('voice') === 'none' ? '' : $this->option('voice'),
            'music' => $this->option('music') === 'none' ? '' : $this->option('music'),
        ], fn ($v) => $v !== null), 'Queued from the command line');

        if (! $this->option('json')) {
            $known = Pricing::known($video->model);
            $this->components->twoColumnDetail('Estimated AI cost', $known
                ? sprintf('$%.2f – $%.2f', $video->estimate_low, $video->estimate_high)
                : 'unknown (no prices for this model)');
            $this->components->twoColumnDetail('Spending limit', sprintf('$%.2f', $video->spendLimit()));
            $this->components->twoColumnDetail('Recorded as', $video->record_as_label);
        }

        return $video;
    }

    protected function report(Video $video, ?string $message = null): int
    {
        $ok = in_array($video->status, [VideoStatus::Ready, VideoStatus::Queued], true);
        $result = [
            'id' => $video->id,
            'slug' => $video->slug,
            'title' => $video->title,
            'status' => $video->status->value,
            'error' => $video->error,
            'issues' => $video->issues ?? [],
            'versions' => $video->recordedDevices() ?: $video->deviceList(),
            'seconds' => $video->duration_seconds,
            'speed' => $video->playbackSpeed(),
            'cost' => round($video->cost, 4),
            'published' => $video->isPublished(),
            'studio_url' => route('video-demo.studio.show', $video),
            'library_url' => $video->isPublished() && config('video-demo.library.public.enabled') && $video->visibility === 'public'
                ? route('video-demo.public.show', $video->slug)
                : null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach (array_slice($video->log ?? [], -12) as $entry) {
            $this->line('  <fg=gray>'.substr($entry['at'], 11, 8).'</> '.$entry['message']);
        }
        $this->newLine();

        if (! $ok) {
            $this->components->error($video->error ?? ($video->status === VideoStatus::Stopped ? 'Stopped.' : 'The video could not be made.'));
            foreach ($video->issues ?? [] as $issue) {
                $this->components->twoColumnDetail($issue['path'], $issue['problem'].($issue['detail'] ? ': '.$issue['detail'] : ''));
            }
        } else {
            $this->components->info($message ?? sprintf('"%s" is ready: %s seconds, $%.4f.', $video->title, $video->duration_seconds, $video->cost));
        }
        $this->components->twoColumnDetail('Studio', $result['studio_url']);
        if ($result['library_url']) {
            $this->components->twoColumnDetail('Library', $result['library_url']);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Print why the video couldn't be started, in the chosen format.
     */
    protected function refuse(string $message): int
    {
        $this->option('json')
            ? $this->line(json_encode(['status' => 'error', 'error' => $message], JSON_UNESCAPED_SLASHES))
            : $this->components->error($message);

        return self::FAILURE;
    }
}
