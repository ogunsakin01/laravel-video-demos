<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Support\Language;
use Ogunsakin01\VideoDemo\VideoDemo;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Throwable;

/**
 * Makes a video in other languages from its plan, without planning it again.
 */
class TranslateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:translate
        {slug : The video to translate}
        {languages* : Language codes, such as fr es pt-BR}
        {--voice= : A voice-over voice that speaks them ("none" for none; the video\'s own when it speaks the language)}
        {--publish : Publish each one to the library when it is ready}
        {--queue : Queue them and return straight away, instead of waiting}
        {--json : Print the result as JSON, for scripts and AI agents}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make a video in other languages from its plan: the AI translates the words, then it is recorded';

    /**
     * Execute the console command.
     */
    public function handle(VideoDemo $demo, Publisher $publisher): int
    {
        if (! VideoDemoServiceProvider::studioEnabled()) {
            return $this->refuse('The studio is disabled here. Videos are made where it is enabled, usually your local machine (VIDEO_DEMO_STUDIO=true).');
        }

        $video = $demo->find((string) $this->argument('slug'));
        if (! $video) {
            return $this->refuse("There is no video [{$this->argument('slug')}].");
        }

        $options = $this->option('voice') !== null ? ['voice' => $this->option('voice') === 'none' ? null : $this->option('voice')] : [];
        $results = [];
        $failed = false;
        foreach ($this->argument('languages') as $language) {
            try {
                if (! $this->option('json') && ! $this->option('queue')) {
                    $this->components->info('Making the '.Language::name($language).' version. This takes a few minutes.');
                }
                $translation = $demo->translate($video, $language, [...$options, 'queue' => (bool) $this->option('queue')]);
            } catch (InvalidArgumentException $e) {
                $results[] = ['language' => $language, 'status' => 'error', 'error' => $e->getMessage()];
                $failed = true;

                continue;
            }

            if ($translation->status === VideoStatus::Ready && $this->option('publish')) {
                try {
                    $publisher->publish($translation);
                    $translation->refresh();
                } catch (Throwable $e) {
                    $translation->error = 'Could not publish: '.$e->getMessage();
                }
            }

            $ok = in_array($translation->status, [VideoStatus::Ready, VideoStatus::Queued], true);
            $failed = $failed || ! $ok;
            $results[] = [
                'language' => $translation->languageCode(),
                'id' => $translation->id,
                'slug' => $translation->slug,
                'title' => $translation->title,
                'status' => $translation->status->value,
                'error' => $translation->error,
                'cost' => round($translation->cost, 4),
                'published' => $translation->isPublished(),
                'studio_url' => route('video-demo.studio.show', $translation),
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['source' => $video->slug, 'videos' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        foreach ($results as $result) {
            $name = Language::name($result['language']);
            match ($result['status']) {
                'queued' => $this->components->info("$name: queued as {$result['slug']}. A queue worker will make it."),
                'ready' => $this->components->info(sprintf('%s: "%s" is ready, for $%.4f%s.', $name, $result['title'], $result['cost'], $result['published'] ? ', and published' : '')),
                default => $this->components->error("$name: ".($result['error'] ?: 'It could not be made.')),
            };
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Print why nothing was started, in the chosen format.
     */
    protected function refuse(string $message): int
    {
        $this->option('json')
            ? $this->line(json_encode(['status' => 'error', 'error' => $message], JSON_UNESCAPED_SLASHES))
            : $this->components->error($message);

        return self::FAILURE;
    }
}
