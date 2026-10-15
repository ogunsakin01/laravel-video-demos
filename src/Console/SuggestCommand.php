<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Ogunsakin01\VideoDemo\Ai\Suggester;
use Throwable;

/**
 * Suggests the videos worth making first, from the app's routes.
 */
class SuggestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:suggest
        {--model= : The AI model (the cheapest reliable one when left out)}
        {--replace : Delete the ideas not yet made into videos and start over}
        {--json : Print the suggestions as JSON, for scripts and AI agents}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Suggest the videos worth making first for this app';

    /**
     * Execute the console command.
     */
    public function handle(Suggester $suggester): int
    {
        try {
            $result = $suggester->suggest($this->option('model') ?: null, (bool) $this->option('replace'));
        } catch (Throwable $e) {
            $this->option('json')
                ? $this->line(json_encode(['status' => 'error', 'error' => $e->getMessage()]))
                : $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($result['suggestions'] as $i => $s) {
            $this->newLine();
            $this->line(sprintf('<options=bold>%d. %s</>  <fg=gray>%s · %ds%s%s</>', $i + 1, $s['title'], $s['style'], $s['target_seconds'], $s['start_path'] ? ' · '.$s['start_path'] : '', $s['record_as'] ? ' · as '.$s['record_as'] : ''));
            $this->line('   '.$s['prompt']);
            $this->line('   <fg=gray>'.$s['why'].'</>');
        }
        $this->newLine();
        $this->components->info('Saved to the studio\'s Ideas page. Make one with: php artisan video-demo:generate "<description>" --style=<style> --length=<seconds>');

        return self::SUCCESS;
    }
}
