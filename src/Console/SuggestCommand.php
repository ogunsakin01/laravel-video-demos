<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Ogunsakin01\VideoDemo\Ai\Suggester;
use Throwable;

/** Videos worth making first, suggested from the app's routes */
class SuggestCommand extends Command
{
    protected $signature = 'video-demo:suggest
        {--model= : The AI model (video-demo.ai.model when left out)}
        {--json : Print the suggestions as JSON, for scripts and AI agents}';

    protected $description = 'Suggest the videos worth making first for this app';

    public function handle(Suggester $suggester): int
    {
        try {
            $result = $suggester->suggest($this->option('model') ?: null);
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
            $this->line(sprintf('<options=bold>%d. %s</>  <fg=gray>%s · %ds%s</>', $i + 1, $s['title'], $s['style'], $s['target_seconds'], $s['start_path'] ? ' · '.$s['start_path'] : ''));
            $this->line('   '.$s['prompt']);
            $this->line('   <fg=gray>'.$s['why'].'</>');
        }
        $this->newLine();
        $this->components->info('Make one with: php artisan video-demo:generate "<description>" --style=<style> --length=<seconds>');

        return self::SUCCESS;
    }
}
