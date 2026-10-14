<?php

namespace Ogunsakin01\VideoDemo\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\SampleData\Builder;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;

/**
 * Builds and fills a video's sample database. The package runs it in its
 * own PHP process, pointed at the sandbox; it is not meant to be run by hand.
 */
class SampleDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video-demo:sample-data {action : build, prepare, seed or query} {input : The job file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build and fill a video\'s sample database (used by the package)';

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * Execute the console command.
     */
    public function handle(Builder $builder): int
    {
        // Only ever on a sandbox: never let this touch the app's own database.
        if (config('database.default') !== Sandbox::CONNECTION) {
            $this->components->error('This command only runs on a video\'s sample database.');

            return self::FAILURE;
        }

        $input = json_decode((string) File::get($this->argument('input')), true) ?: [];
        $result = match ($this->argument('action')) {
            'build' => $builder->build($input),
            'prepare' => $builder->prepare($input),
            'seed' => $builder->seed($input),
            'query' => $builder->query($input),
            default => ['ok' => false, 'error' => 'Unknown action.'],
        };

        File::put($this->argument('input').'.out', json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        return self::SUCCESS;
    }
}
