<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\CostEstimator;
use Ogunsakin01\VideoDemo\Ai\Pricing;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Jobs\GenerateVideo;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\RecordAs;
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
        {--as= : Record as a type of user, such as "admin", "guest" for signed out, or a user id or email}
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
    public function handle(CostEstimator $estimator, CodeTools $code, Publisher $publisher): int
    {
        if (! VideoDemoServiceProvider::studioEnabled()) {
            return $this->refuse('The studio is disabled here. Videos are made where it is enabled, usually your local machine (VIDEO_DEMO_STUDIO=true).');
        }

        try {
            $video = $this->createVideo($estimator, $code);
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
    protected function createVideo(CostEstimator $estimator, CodeTools $code): Video
    {
        $model = $this->option('model') ?: Pricing::defaultModel();
        if (! Pricing::model($model)) {
            throw new \InvalidArgumentException("Unknown model [$model]. Choose one of: ".implode(', ', array_keys(config('video-demo.ai.models'))).'.');
        }

        $effort = $this->option('effort') ?: config('video-demo.ai.effort');
        if (! in_array($effort, ['low', 'medium', 'high', 'xhigh', 'max'], true)) {
            throw new \InvalidArgumentException("Unknown effort [$effort]. Choose low, medium, high, xhigh or max.");
        }

        $devices = $this->option('devices')
            ? array_map('trim', explode(',', $this->option('devices')))
            : Devices::defaults();
        if ($unknown = array_diff($devices, Devices::keys())) {
            throw new \InvalidArgumentException('Unknown versions: '.implode(', ', $unknown).'. Choose from: '.implode(', ', Devices::keys()).'.');
        }

        $style = $this->style();
        $seconds = (int) $this->option('length');
        $max = (int) config('video-demo.limits.max_seconds', 300);
        if ($seconds < 10 || $seconds > $max) {
            throw new \InvalidArgumentException("The length must be between 10 and $max seconds.");
        }

        $start = $this->option('start');
        if ($start !== null && ! str_starts_with($start, '/')) {
            throw new \InvalidArgumentException('The start page must be a path, such as /dashboard.');
        }

        $maxCost = $this->option('max-cost');
        if ($maxCost !== null && (! is_numeric($maxCost) || $maxCost <= 0)) {
            throw new \InvalidArgumentException('The spending limit must be a number of dollars above 0, such as 0.50.');
        }

        $maxSteps = $this->option('max-steps');
        if ($maxSteps !== null && (! ctype_digit((string) $maxSteps) || $maxSteps < 3)) {
            throw new \InvalidArgumentException('Max steps must be a whole number of at least 3.');
        }

        $recordAs = $this->option('as') ?: null;
        if ($recordAs !== null) {
            try {
                $recordAs = RecordAs::parse($recordAs);
            } catch (\RuntimeException) {
                $types = implode(', ', array_keys(RecordAs::types()));
                throw new \InvalidArgumentException("Unknown user type [$recordAs]. Choose guest".($types ? ", $types" : '').', or a user id or email.');
            }
        }

        $estimate = $estimator->estimate($model, $effort, $seconds, $code->routeCount());

        $video = Video::create([
            'title' => $this->option('title') ?: null,
            'prompt' => (string) $this->argument('description'),
            'start_path' => $start,
            'target_seconds' => $seconds,
            'model' => $model,
            'effort' => $effort,
            'visibility' => $this->option('private') ? 'private' : 'public',
            'style' => $style->snapshot(),
            'devices' => array_values(array_intersect(Devices::keys(), $devices)),
            'max_cost' => $maxCost !== null ? (float) $maxCost : null,
            'max_steps' => $maxSteps !== null ? (int) $maxSteps : null,
            'record_as' => $recordAs,
            'record_as_label' => RecordAs::label($recordAs),
            'status' => VideoStatus::Queued,
            'estimate_low' => $estimate['low'],
            'estimate_high' => $estimate['high'],
        ]);
        $video->addLog('Queued from the command line');

        if (! $this->option('json')) {
            $this->components->twoColumnDetail('Estimated AI cost', $estimate['known']
                ? sprintf('$%.2f – $%.2f', $estimate['low'], $estimate['high'])
                : 'unknown (no prices for this model)');
            $this->components->twoColumnDetail('Spending limit', sprintf('$%.2f', $video->spendLimit()));
            $this->components->twoColumnDetail('Recorded as', $video->record_as_label);
        }

        return $video;
    }

    /**
     * Find the style named by the --style option, or the first style.
     */
    protected function style(): VideoStyle
    {
        $styles = VideoStyle::options();
        $wanted = $this->option('style');

        if (! $wanted) {
            return $styles->first() ?? throw new \InvalidArgumentException('There are no styles. Add one on the studio\'s Styles page or in video-demo.styles.');
        }

        return $styles->first(fn (VideoStyle $s) => $s->key === $wanted || Str::lower($s->name) === Str::lower($wanted))
            ?? throw new \InvalidArgumentException("Unknown style [$wanted]. Choose one of: ".$styles->pluck('key')->implode(', ').'.');
    }

    /**
     * Print the video's outcome, as text or JSON, and return the exit code.
     */
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
