<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
    }

    protected function getPackageProviders($app): array
    {
        // Prism is optional; in apps that install it, Laravel discovers it
        return array_filter([
            VideoDemoServiceProvider::class,
            class_exists(PrismServiceProvider::class) ? PrismServiceProvider::class : null,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.env', 'local');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        // testbench.yaml configures the demo app; tests keep everything in memory
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('video-demo.studio.enabled', true);
        $app['config']->set('video-demo.library.private.middleware', ['web']);
        $app['config']->set('video-demo.ai.api_key', 'test-key');
        // Tests don't open real browsers unless they ask to (see RecorderTest)
        $app['config']->set('video-demo.recorder.check_pages', false);
    }

    /** Lets the production tests migrate without asking for confirmation */
    protected function migrateFreshUsing(): array
    {
        return [...$this->baseMigrateFreshUsing(), '--force' => true];
    }

    protected function manifestVideo(array $overrides = []): array
    {
        return [
            'slug' => 'post-a-job',
            'title' => 'Post a job',
            'summary' => 'From the dashboard to a published listing.',
            'visibility' => 'public',
            'duration' => 62.4,
            'chapters' => [['title' => 'Fill in the details', 'at' => 4.2]],
            'video' => 'video-demo/post-a-job-v1.mp4',
            'poster' => 'video-demo/post-a-job-v1.jpg',
            'version' => 1,
            'published_at' => now()->toIso8601String(),
            ...$overrides,
        ];
    }
}
