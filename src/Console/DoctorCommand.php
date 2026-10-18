<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Ogunsakin01\VideoDemo\Support\Doctor;

/**
 * Checks everything making videos needs on this machine, and says how to fix
 * what isn't right: the studio, the AI key, the app's address, a separate
 * frontend and its sign-in, Node, Playwright and its browser, ffmpeg, the
 * queue and a worker, the user to record as, sample data, where published
 * videos go, and the voice. It changes nothing and costs no AI.
 */
class DoctorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:doctor {--json : Print the checks as JSON, for scripts and CI}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check that everything making videos needs is set up here, and say how to fix what isn\'t';

    /**
     * The checks: status (ok, warn or fail), what was checked, what was found and how to fix it.
     *
     * @var list<array{status: string, check: string, found: string, fix: ?string}>
     */
    protected array $checks = [];

    /**
     * Execute the console command.
     */
    public function handle(Doctor $doctor): int
    {
        $this->checks = $doctor->run();

        $failed = collect($this->checks)->where('status', 'fail')->count();
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $failed === 0, 'checks' => $this->checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->print($failed);
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Print the checks for a person.
     */
    protected function print(int $failed): void
    {
        $this->newLine();
        foreach ($this->checks as $check) {
            $mark = ['ok' => '<fg=green>✓</>', 'warn' => '<fg=yellow>!</>', 'fail' => '<fg=red>✗</>'][$check['status']];
            $this->components->twoColumnDetail("$mark {$check['check']}", $check['found']);
            if ($check['fix'] && $check['status'] !== 'ok') {
                $this->line("    <fg=gray>→ {$check['fix']}</>");
            }
        }
        $this->newLine();

        $warnings = collect($this->checks)->where('status', 'warn')->count();
        $failed
            ? $this->components->error(sprintf('%d %s to fix before videos can be made.', $failed, $failed === 1 ? 'thing' : 'things'))
            : $this->components->info('Everything needed is set up.'.($warnings ? sprintf(' %d %s worth a look.', $warnings, $warnings === 1 ? 'note' : 'notes') : ''));
    }
}
