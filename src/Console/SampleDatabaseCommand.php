<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use RuntimeException;

/**
 * Builds the base sample database ahead of time, so the first video
 * recorded on sample data doesn't wait for the app's migrations and seeders.
 */
class SampleDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:sample-database {--fresh : Build it again even if it is up to date}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build the sample database videos can be recorded on: your migrations, seeders and a user of each type';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Building the sample database. Your migrations and seeders run on a separate SQLite file; your own database is not touched.');

        $base = null;
        try {
            $this->components->task('Migrations, seeders and a user of each type', function () use (&$base) {
                $base = Sandbox::base((bool) $this->option('fresh'));
            });
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $info = $base['info'] ?? [];
        foreach ($info['warnings'] ?? [] as $warning) {
            $this->components->warn($warning);
        }
        $this->components->twoColumnDetail('Seeders run', implode(', ', $info['seeded'] ?? []) ?: 'none');
        foreach ($info['users'] ?? [] as $type => $user) {
            $this->components->twoColumnDetail("$type user", $user);
        }
        $this->components->twoColumnDetail('Tables with rows', (string) count($info['tables'] ?? []));
        $this->newLine();
        $this->line('  Each video recorded on sample data gets its own copy. It is built again when a migration, seeder or factory changes.');

        return self::SUCCESS;
    }
}
