<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Facades\VideoDemo;
use Ogunsakin01\VideoDemo\Library\Manifest;
use Ogunsakin01\VideoDemo\Library\Publisher;
use Ogunsakin01\VideoDemo\Models\Video;

/**
 * "Videos for this page": the videos that show the page a user is on.
 */
class HelpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');

        Route::get('/jobs', fn () => Blade::render('<x-video-demo::help />'))->name('jobs.index');
        Route::get('/jobs/create', fn () => Blade::render('<x-video-demo::help :open="true" />'))->name('jobs.create');
        Route::get('/jobs/{job}', fn () => Blade::render('<x-video-demo::help />'))->name('jobs.show');
        Route::get('/settings', fn () => Blade::render('<x-video-demo::help />'));
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_publishing_keeps_the_routes_a_video_shows(): void
    {
        $video = Video::create([
            'title' => 'Post a job', 'prompt' => 'How to post a job', 'target_seconds' => 60, 'model' => 'claude-opus-5-5', 'effort' => 'high',
            'status' => VideoStatus::Ready, 'version' => 1, 'devices' => ['desktop'], 'recordings' => ['desktop' => ['seconds' => 30, 'chapters' => []]],
            'plan' => ['title' => 'Post a job', 'summary' => '', 'steps' => [
                ['action' => 'goto', 'url' => '/jobs/create'], ['action' => 'goto', 'url' => '/jobs/42'], ['action' => 'goto', 'url' => '/jobs/7'], ['action' => 'goto', 'url' => '/nowhere'],
            ]],
        ]);
        Storage::disk('local')->put($video->workFile('desktop'), 'mp4');
        Storage::disk('local')->put($video->workFile('desktop', 'poster'), 'jpg');

        app(Publisher::class)->publish($video);

        $this->assertSame([['name' => 'jobs.create', 'uri' => 'jobs/create'], ['name' => 'jobs.show', 'uri' => 'jobs/{job}']], app(Manifest::class)->find('post-a-job', 'public')['routes']);
    }

    public function test_the_help_button_lists_the_videos_for_the_page_it_is_on(): void
    {
        $manifest = app(Manifest::class);
        $manifest->put($this->manifestVideo(['routes' => [['name' => 'jobs.create', 'uri' => 'jobs/create'], ['name' => 'jobs.index', 'uri' => 'jobs']]]));
        $manifest->put($this->manifestVideo(['slug' => 'edit-a-job', 'title' => 'Edit a job', 'duration' => 30, 'routes' => [['name' => 'jobs.show', 'uri' => 'jobs/{job}'], ['name' => 'jobs.create', 'uri' => 'jobs/create']]]));
        $manifest->put($this->manifestVideo(['slug' => 'payroll', 'title' => 'Run payroll', 'visibility' => 'private', 'routes' => [['name' => 'jobs.index', 'uri' => 'jobs']]]));
        // Published before routes were kept.
        $manifest->put($this->manifestVideo(['slug' => 'old', 'title' => 'An old video']));

        $this->get('/jobs')
            ->assertSee('Videos for this page')
            ->assertSee('Post a job')
            ->assertDontSee('Edit a job')
            ->assertDontSee('Run payroll')
            ->assertSee('src="/storage/video-demo/post-a-job-v1.mp4"', false)
            ->assertSee('preload="none"', false)
            ->assertSee('/help/videos">All videos</a>', false);

        // A page with a parameter matches by its route; both videos show the
        // create page, the one that starts there first.
        $this->get('/jobs/9')->assertSee('Edit a job')->assertDontSee('Post a job');
        $this->get('/jobs/create')->assertSeeInOrder(['Post a job', 'Edit a job'])->assertSee('<details class="vd-help" open', false);

        // Pages no video shows render nothing.
        $this->get('/settings')->assertDontSee('vd-help');
    }

    public function test_another_page_can_be_named_and_the_facade_lists_them_too(): void
    {
        $manifest = app(Manifest::class);
        $manifest->put($this->manifestVideo(['routes' => [['name' => 'jobs.create', 'uri' => 'jobs/create']]]));
        $manifest->put($this->manifestVideo(['slug' => 'payroll', 'title' => 'Run payroll', 'visibility' => 'private', 'routes' => [['name' => null, 'uri' => 'settings']]]));

        $this->assertStringContainsString('Post a job', Blade::render('<x-video-demo::help route="jobs.create" />'));
        $this->assertStringContainsString('Post a job', Blade::render('<x-video-demo::help route="/jobs/create" />'));
        $this->assertStringContainsString('Run payroll', Blade::render('<x-video-demo::help route="/settings" area="private" label="Help with settings" />'));
        $this->assertStringContainsString('Help with settings', Blade::render('<x-video-demo::help route="/settings" area="private" label="Help with settings" />'));
        $this->assertSame('', trim(Blade::render('<x-video-demo::help route="/settings" />')));

        $this->assertSame(['post-a-job'], array_column(VideoDemo::forPage('jobs.create'), 'slug'));
        $this->assertSame(['post-a-job'], array_column(VideoDemo::forPage('/jobs/create'), 'slug'));
        $this->assertSame(['payroll'], array_column(VideoDemo::forPage('/settings', 'private'), 'slug'));
        $this->assertSame([], VideoDemo::forPage('/settings'));
    }
}
