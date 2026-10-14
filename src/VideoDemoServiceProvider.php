<?php

namespace Ogunsakin01\VideoDemo;

use Anthropic\Client;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ogunsakin01\VideoDemo\Ai\Claude;
use Ogunsakin01\VideoDemo\Console\GenerateCommand;
use Ogunsakin01\VideoDemo\Console\InstallCommand;
use Ogunsakin01\VideoDemo\Console\ListCommand;
use Ogunsakin01\VideoDemo\Console\PublishCommand;
use Ogunsakin01\VideoDemo\Console\RefreshCommand;
use Ogunsakin01\VideoDemo\Console\SuggestCommand;
use Ogunsakin01\VideoDemo\Library\Manifest;

class VideoDemoServiceProvider extends ServiceProvider
{
    /**
     * The name of the package's queue connection, which has a long retry_after.
     */
    public const QUEUE_CONNECTION = 'video-demo';

    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/video-demo.php', 'video-demo');
        $this->registerQueueConnection();

        $this->app->singleton(Manifest::class);

        // The package gets its own client, so it never changes how the app
        // itself talks to Anthropic.
        $this->app->when(Claude::class)
            ->needs(Client::class)
            ->give(fn () => new Client(apiKey: config('video-demo.ai.api_key')));
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'video-demo');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/video-demo.php' => config_path('video-demo.php')], 'video-demo-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/video-demo')], 'video-demo-views');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'video-demo-migrations');

            $this->commands([
                InstallCommand::class,
                GenerateCommand::class,
                ListCommand::class,
                PublishCommand::class,
                RefreshCommand::class,
                SuggestCommand::class,
            ]);
        }
    }

    /**
     * Register a copy of the app's default queue connection for long videos.
     *
     * Generation runs for minutes. With Laravel's usual retry_after of 90
     * seconds, the queue would start the same job again while it runs.
     */
    protected function registerQueueConnection(): void
    {
        $default = config('queue.default');
        $base = config("queue.connections.$default");

        if (! is_array($base) || config('queue.connections.'.self::QUEUE_CONNECTION)) {
            return;
        }

        config(['queue.connections.'.self::QUEUE_CONNECTION => [
            ...$base,
            'retry_after' => (int) config('video-demo.queue.retry_after', 7200),
            // Its own queue, so the app's workers (with their shorter
            // retry_after) never pick up a long video and start it again.
            'queue' => config('video-demo.queue.name') ?: 'video-demo',
        ]]);
    }

    /**
     * Get a warning about queue settings that would break long videos, if any.
     */
    public static function queueWarning(): ?string
    {
        $connection = config('video-demo.queue.connection') ?: self::QUEUE_CONNECTION;
        $settings = config("queue.connections.$connection", []);

        if (($settings['driver'] ?? null) === 'sync') {
            return 'Your queue runs jobs straight away (QUEUE_CONNECTION=sync), so making a video holds the page open for minutes. Use the database or redis queue and run php artisan queue:work video-demo.';
        }
        if (isset($settings['retry_after']) && $settings['retry_after'] < 1800) {
            return "The [$connection] queue connection has retry_after={$settings['retry_after']}: a video taking longer than that is started a second time. Leave VIDEO_DEMO_QUEUE_CONNECTION empty to use the package's own connection, or raise retry_after.";
        }

        return null;
    }

    /**
     * Determine if the studio is enabled in this environment.
     */
    public static function studioEnabled(): bool
    {
        if (! config('video-demo.studio.enabled')) {
            return false;
        }

        return ! app()->environment('production') || config('video-demo.studio.allow_in_production');
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        if (static::studioEnabled()) {
            Route::group($this->groupOptions('studio', 'video-demo.studio.'), __DIR__.'/../routes/studio.php');
        }

        foreach (['public', 'private'] as $area) {
            if (config("video-demo.library.$area.enabled")) {
                Route::group(
                    $this->groupOptions("library.$area", "video-demo.$area."),
                    fn () => require __DIR__.'/../routes/library.php',
                );
            }
        }

        if (config('video-demo.library.requests_token')) {
            Route::prefix(config('video-demo.library.private.prefix'))
                ->name('video-demo.api.')
                ->group(__DIR__.'/../routes/api.php');
        }
    }

    /**
     * Get the route group options for the given config key.
     */
    protected function groupOptions(string $key, string $name): array
    {
        return array_filter([
            'prefix' => config("video-demo.$key.prefix"),
            'middleware' => config("video-demo.$key.middleware"),
            'domain' => config("video-demo.$key.domain"),
            'as' => $name,
        ]);
    }
}
