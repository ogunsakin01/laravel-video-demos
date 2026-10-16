<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Prism\PrismServiceProvider;

/**
 * The base test case: the package in a Testbench app, with an in-memory setup.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
    }

    /**
     * The folder holding this test's local and public disks.
     */
    protected string $disks;

    protected function getPackageProviders($app): array
    {
        // Prism is optional; in apps that install it, Laravel discovers it.
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
        // testbench.yaml configures the demo app; tests keep everything in memory.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('video-demo.studio.enabled', true);
        $app['config']->set('video-demo.library.private.middleware', ['web']);
        $app['config']->set('video-demo.ai.api_key', 'test-key');
        // Tests don't open real browsers unless they ask to (see RecorderTest).
        $app['config']->set('video-demo.recorder.check_pages', false);
        // Sample data is on by default; tests of other features record on the test database.
        $app['config']->set('video-demo.sample_data.default', false);

        // Files go to a folder of the test's own, never the demo app's storage:
        // a test video #1 deleting its files must not take the demo's video #1 with it.
        $this->disks = sys_get_temp_dir().'/video-demo-test-disks-'.uniqid();
        $app['config']->set('filesystems.disks.local.root', $this->disks.'/local');
        $app['config']->set('filesystems.disks.public.root', $this->disks.'/public');
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->disks));
    }

    /**
     * Let the production tests migrate without asking for confirmation.
     */
    protected function migrateFreshUsing(): array
    {
        return [...$this->baseMigrateFreshUsing(), '--force' => true];
    }

    /**
     * Build a library manifest entry for a published video.
     */
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
