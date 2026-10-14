<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Models\VideoStyle;

class ExamplesTest extends TestCase
{
    public function test_the_studio_shows_sample_videos_from_the_package(): void
    {
        $this->get('/video-demo/studio')->assertSee('Examples')->assertSee('See examples');

        $this->get('/video-demo/studio/examples')
            ->assertOk()
            ->assertSee('A product tour')
            ->assertSee('https://cdn.jsdelivr.net/gh/ogunsakin01/laravel-video-demos@v0.4.0/docs/studio-tour.mp4', false)
            ->assertSee('workbench/demo-assets/sample-tablet.mp4', false)
            ->assertSee('Make one like this');
    }

    public function test_make_one_like_this_picks_the_examples_style(): void
    {
        $ad = VideoStyle::options()->firstWhere('key', 'ad');

        $this->get('/video-demo/studio/create?style=ad')
            ->assertOk()
            ->assertSee('<option value="'.$ad->id.'" data-direction="'.e($ad->direction).'" selected', false);
    }

    public function test_the_page_can_be_turned_off_or_hosted_elsewhere(): void
    {
        config(['video-demo.ui.examples_url' => 'https://videos.example.com/demos/']);
        $this->get('/video-demo/studio/examples')->assertSee('https://videos.example.com/demos/docs/studio-tour.mp4', false);

        config(['video-demo.ui.examples' => false]);
        $this->get('/video-demo/studio/examples')->assertNotFound();
        $this->get('/video-demo/studio')->assertDontSee('See examples');
    }
}
