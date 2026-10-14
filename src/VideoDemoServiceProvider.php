<?php

namespace Ogunsakin01\VideoDemo;

use Anthropic\Client;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ogunsakin01\VideoDemo\Ai\Claude;
use Ogunsakin01\VideoDemo\Console\InstallCommand;
use Ogunsakin01\VideoDemo\Console\RefreshCommand;
use Ogunsakin01\VideoDemo\Library\Manifest;

class VideoDemoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/video-demo.php', 'video-demo');

        $this->app->singleton(Manifest::class);

        // The package gets its own client, so it never changes how the app
        // itself talks to Anthropic
        $this->app->when(Claude::class)
            ->needs(Client::class)
            ->give(fn () => new Client(apiKey: config('video-demo.ai.api_key')));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'video-demo');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/video-demo.php' => config_path('video-demo.php')], 'video-demo-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/video-demo')], 'video-demo-views');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'video-demo-migrations');

            $this->commands([InstallCommand::class, RefreshCommand::class]);
        }
    }

    public static function studioEnabled(): bool
    {
        if (! config('video-demo.studio.enabled')) {
            return false;
        }

        return ! app()->environment('production') || config('video-demo.studio.allow_in_production');
    }

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
