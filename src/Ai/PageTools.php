<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use RuntimeException;

/**
 * Lets the AI model look at a page while it plans, as the video's user and
 * on its sample data, when the code doesn't settle what the page shows: a
 * label from a translation, a component or the database, or a page whose
 * code it can't read (a separate frontend). It gets the page's headings,
 * links, buttons, fields with their labels and text, so its plan uses the
 * labels the recording will find. Each look adds what it returns to the
 * conversation, so the model may look a few times per video at most
 * (video-demo.limits.page_looks).
 */
class PageTools
{
    public const TOOL = 'look_at_page';

    /**
     * How many times the model has looked.
     */
    public int $looks = 0;

    /**
     * Create a new page tools instance for a video.
     */
    public function __construct(protected Video $video, protected Recorder $recorder, protected Redactor $redactor = new Redactor) {}

    /**
     * Make the tools for a video, recording on its sample database when it has one.
     */
    public static function for(Video $video, ?Sandbox $sandbox = null): self
    {
        $recorder = app(Recorder::class);
        $recorder->sandbox = $sandbox;
        $recorder->locale = $video->recordingLocale();

        return new self($video, $recorder);
    }

    /**
     * Determine if the model may look at pages at all.
     */
    public static function enabled(): bool
    {
        return static::limit() > 0;
    }

    /**
     * Get how many times the model may look at a page for one video.
     */
    public static function limit(): int
    {
        return (int) config('video-demo.limits.page_looks', 3);
    }

    /**
     * Get the tool definition.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return [[
            'name' => self::TOOL,
            'description' => 'Open a page of the app in the recorder\'s browser, signed in as the video\'s user, and get what is on it as a user sees it: headings, links, buttons, form fields with their labels, and text. Use it only when the code doesn\'t settle what a page shows, such as a label that comes from a translation, a component or the database, or a page whose code you can\'t read. It costs about as much as reading a long file. At most '.static::limit().' per video.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'The page\'s path, such as /jobs/create'],
                ],
                'required' => ['path'],
                'additionalProperties' => false,
            ],
        ]];
    }

    /**
     * Determine if a tool call is this one.
     */
    public function handles(string $name): bool
    {
        return $name === self::TOOL;
    }

    /**
     * Look at the page, returning what is on it for the model.
     *
     * @throws RuntimeException When the model can't look, saying why.
     */
    public function run(array $input): string
    {
        $path = trim((string) ($input['path'] ?? ''));
        if (! str_starts_with($path, '/')) {
            throw new RuntimeException('Give the page\'s path, starting with "/", such as /jobs/create.');
        }
        if ($this->looks >= static::limit()) {
            throw new RuntimeException('You have looked at '.static::limit().' pages, the most for one video. Plan from the code and what you have seen.');
        }
        $this->looks++;

        $page = $this->recorder->lookAtPage($path, $this->video->record_as, $this->video->deviceList()[0] ?? 'desktop', shouldStop: fn () => $this->video->stopRequested());
        if (! $page['ok']) {
            throw new RuntimeException("The page at $path could not be opened: ".($page['error'] ?? 'no reason given').'.');
        }

        // Anything sent to a model is redacted, page text too.
        return $this->redactor->redact(implode("\n", [
            "The page at {$page['url']}".(! empty($page['title']) ? " (title: {$page['title']})" : '').(($page['status'] ?? 200) >= 400 ? ", HTTP {$page['status']}" : '').':',
            "<page>\n".($page['snapshot'] ?: '(nothing visible)')."\n</page>",
            'Use these labels exactly as shown.',
        ]));
    }

    /**
     * Describe a call for the video's log.
     */
    public function describe(array $input): string
    {
        return 'Looked at '.($input['path'] ?? 'a page');
    }
}
