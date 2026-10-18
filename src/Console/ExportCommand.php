<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Exporter;
use Ogunsakin01\VideoDemo\VideoDemo;
use Throwable;

/**
 * Makes a copy of a finished video as a GIF, or a square or vertical MP4.
 */
class ExportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:export
        {slug : The video}
        {--format=gif : gif, square, vertical, or pest for a Pest browser test made from its plan}
        {--device= : The version to make it from (the primary one when left out)}
        {--out= : Where to save it, such as docs/post-a-job.gif (it stays in the studio\'s files when left out)}
        {--json : Print the result as JSON, for scripts and AI agents}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make a GIF, or a square or vertical MP4, of a finished video, at no AI cost';

    /**
     * Execute the console command.
     */
    public function handle(VideoDemo $demo): int
    {
        $video = $demo->find((string) $this->argument('slug'));
        if (! $video || $video->status !== VideoStatus::Ready) {
            return $this->refuse($video ? "\"{$video->slug}\" isn't ready: it is {$video->status->label()}." : "There is no video [{$this->argument('slug')}].");
        }
        $format = (string) $this->option('format');
        if ($format === 'pest') {
            return $this->pest($demo, $video);
        }
        if (! isset(Exporter::FORMATS[$format])) {
            return $this->refuse("Unknown format [$format]. Choose ".implode(', ', array_keys(Exporter::FORMATS)).'.');
        }

        try {
            if (! $this->option('json')) {
                $this->components->info('Making the '.Exporter::FORMATS[$format]['label'].' copy. This takes a minute or two.');
            }
            $file = $demo->export($video, $format, $this->option('device') ?: null);
            if ($out = $this->option('out')) {
                $out = str_starts_with($out, '/') ? $out : base_path($out);
                File::ensureDirectoryExists(dirname($out));
                File::copy($file, $out);
                $file = $out;
            }
        } catch (Throwable $e) {
            return $this->refuse($e->getMessage());
        }

        $result = ['slug' => $video->slug, 'format' => $format, 'file' => $file, 'bytes' => filesize($file)];
        $this->option('json')
            ? $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            : $this->components->info(sprintf('Saved %s (%.1f MB).', $file, $result['bytes'] / 1_048_576));

        return self::SUCCESS;
    }

    /**
     * Write the video's plan as a Pest browser test, to --out (such as
     * tests/Browser/PostAJobTest.php), or print it.
     */
    protected function pest(VideoDemo $demo, Video $video): int
    {
        try {
            $test = $demo->pestTest($video);
        } catch (Throwable $e) {
            return $this->refuse($e->getMessage());
        }

        if (! ($out = $this->option('out'))) {
            $this->option('json')
                ? $this->line(json_encode(['slug' => $video->slug, 'format' => 'pest', 'test' => $test], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                : $this->line($test);

            return self::SUCCESS;
        }

        $out = str_starts_with($out, '/') ? $out : base_path($out);
        File::ensureDirectoryExists(dirname($out));
        File::put($out, $test);
        $this->option('json')
            ? $this->line(json_encode(['slug' => $video->slug, 'format' => 'pest', 'file' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            : $this->components->info("Saved $out. Run it with Pest's browser plugin (pestphp/pest-plugin-browser).");

        return self::SUCCESS;
    }

    /**
     * Print why nothing was made, in the chosen format.
     */
    protected function refuse(string $message): int
    {
        $this->option('json')
            ? $this->line(json_encode(['status' => 'error', 'error' => $message], JSON_UNESCAPED_SLASHES))
            : $this->components->error($message);

        return self::FAILURE;
    }
}
