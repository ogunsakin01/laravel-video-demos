<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ogunsakin01\VideoDemo\Ai\Claude;
use Workbench\App\Console\RecordToursCommand;
use Workbench\App\DemoClaude;

/**
 * A small demo app for trying the package and recording its own tour videos:
 * vendor/bin/testbench workbench:build, then vendor/bin/testbench serve.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Configure the demo app, and use DemoClaude when no API key is set.
     */
    public function register(): void
    {
        config([
            'app.name' => 'Acme HR',
            'video-demo.studio.enabled' => true,
            // No sign-in in the demo, so the private library is open.
            'video-demo.library.private.middleware' => ['web'],
            // Count plays, so the studio shows how the videos are watched.
            'video-demo.library.stats' => true,
            'video-demo.ai.api_key' => env('ANTHROPIC_API_KEY', 'demo-key'),
            'video-demo.ui.name' => 'Acme HR videos',
            'filesystems.disks.demo-videos' => [
                'driver' => 'local',
                'root' => public_path('demo-videos'),
                'url' => '/demo-videos',
                'visibility' => 'public',
                'throw' => true,
            ],
            'video-demo.storage.disk' => 'demo-videos',
            // The demo app has no roles, so its types of user are listed here (DemoUsersSeeder adds them).
            'video-demo.recorder.user_types' => ['Recruiter' => 'recruiter@acme.test', 'Hiring manager' => 'manager@acme.test'],
        ]);

        if (! env('ANTHROPIC_API_KEY')) {
            $this->app->bind(Claude::class, DemoClaude::class);
        }
    }

    /**
     * Load the demo app's views, migrations, routes and commands.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'workbench');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        Route::middleware('web')->group(__DIR__.'/../../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([RecordToursCommand::class]);
        }
    }
}
