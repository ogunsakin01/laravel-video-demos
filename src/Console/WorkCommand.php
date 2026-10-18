<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Runs several queue workers for the package's queue, so several videos are
 * made at once. Each video records in its own browser on its own copy of the
 * sample database, so they don't get in each other's way.
 */
class WorkCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:work
        {--workers= : How many videos to make at once (video-demo.queue.workers, 2 by default)}
        {--stop-when-empty : Stop each worker once the queue is empty, instead of waiting for more videos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make several videos at once: run that many queue workers for the package\'s queue';

    /**
     * Whether Ctrl+C or a stop signal has been received.
     */
    protected bool $stopping = false;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = max(1, min(16, (int) ($this->option('workers') ?: config('video-demo.queue.workers', 2))));
        $connection = VideoDemoServiceProvider::queueConnection();
        $command = [(new PhpExecutableFinder)->find(false) ?: 'php', base_path('artisan'), 'queue:work', $connection,
            '--queue='.VideoDemoServiceProvider::queueName(), ...($this->option('stop-when-empty') ? ['--stop-when-empty'] : [])];

        if ($warning = VideoDemoServiceProvider::queueWarning()) {
            $this->components->warn($warning);
        }
        $this->components->info("Making up to $count videos at once. Press Ctrl+C to stop.");
        $this->line('<fg=gray>Each video opens its own browser, so your app must answer several requests at once: Herd, Valet, Sail or Octane do; with php artisan serve, set PHP_CLI_SERVER_WORKERS=4 or more.</>');

        // Signals need pcntl, which Windows doesn't have; there Ctrl+C ends the workers with this command.
        if (defined('SIGINT')) {
            $this->trap([SIGINT, SIGTERM], function (): void {
                $this->stopping = true;
            });
        }

        /** @var array<int, InvokedProcess> $workers */
        $workers = [];
        for ($n = 1; $n <= $count; $n++) {
            // A moment apart, so two workers don't reach for the same video at once.
            if ($n > 1) {
                usleep(1_500_000);
            }
            $workers[$n] = $this->start($command);
        }

        while ($workers !== []) {
            foreach ($workers as $n => $worker) {
                $this->relay($n, $worker);
                if ($worker->running()) {
                    if ($this->stopping) {
                        $worker->signal(SIGTERM);
                    }

                    continue;
                }

                $this->relay($n, $worker);
                $result = $worker->wait();
                // A worker that stopped by itself (a crash, or its memory limit) is started again,
                // and so is one that found the queue empty while another worker was taking a video.
                $done = $this->option('stop-when-empty') && $result->successful() && $this->waiting($connection) === 0;
                if (! $this->stopping && ! $done) {
                    $this->line("<fg=yellow>[$n]</> stopped (exit code {$result->exitCode()}); starting it again");
                    $workers[$n] = $this->start($command);
                } else {
                    unset($workers[$n]);
                }
            }
            usleep(300_000);
        }

        return self::SUCCESS;
    }

    /**
     * Get how many videos are waiting for a worker.
     */
    protected function waiting(string $connection): int
    {
        try {
            return Queue::connection($connection)->size(VideoDemoServiceProvider::queueName());
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Start one worker.
     *
     * @param  list<string>  $command
     */
    protected function start(array $command): InvokedProcess
    {
        return Process::path(base_path())->forever()->start($command);
    }

    /**
     * Print what a worker has written since last time, each line marked with
     * the worker's number.
     */
    protected function relay(int $n, InvokedProcess $worker): void
    {
        foreach (preg_split('/\R/', $worker->latestOutput().$worker->latestErrorOutput()) ?: [] as $line) {
            if (trim($line) !== '') {
                $this->line("<fg=gray>[$n]</> $line");
            }
        }
    }
}
