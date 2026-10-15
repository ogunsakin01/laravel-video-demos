<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Models\VideoStyle;
use Ogunsakin01\VideoDemo\Studio\Examples;

/**
 * Tests the studio's Examples page.
 */
class ExamplesTest extends TestCase
{
    public function test_the_studio_shows_sample_videos_from_the_package(): void
    {
        $this->get('/video-demo/studio')->assertSee('Examples')->assertSee('See examples');

        $this->get('/video-demo/studio/examples')
            ->assertOk()
            ->assertSee('Spotlight')
            ->assertSee('https://cdn.jsdelivr.net/gh/ogunsakin01/laravel-video-demos@v0.15.0/docs/examples/example-spotlight.mp4', false)
            ->assertSee('docs/examples/example-quick.mp4', false)
            ->assertSee('Make one like this');

        // One desktop video for each built-in style.
        $this->assertSame(['clean', 'quick', 'detailed', 'showcase', 'ad', 'spotlight', 'promo'], array_column(Examples::all(), 'style_key'));
        $this->assertSame([['desktop']], array_values(array_unique(array_map(fn ($e) => array_keys($e['versions']), Examples::all()), SORT_REGULAR)));
    }

    public function test_make_one_like_this_picks_the_examples_style(): void
    {
        $ad = VideoStyle::options()->firstWhere('key', 'ad');

        $this->get('/video-demo/studio/create?style=ad')
            ->assertOk()
            ->assertSee('<option value="'.$ad->id.'" data-direction="'.e($ad->direction).'" data-voice="" data-music="" data-accent="'.$ad->accent.'" data-theme="'.$ad->theme.'" selected', false);
    }

    public function test_the_page_can_be_turned_off_or_hosted_elsewhere(): void
    {
        config(['video-demo.ui.examples_url' => 'https://videos.example.com/demos/']);
        $this->get('/video-demo/studio/examples')->assertSee('https://videos.example.com/demos/docs/examples/example-clean.mp4', false);

        config(['video-demo.ui.examples' => false]);
        $this->get('/video-demo/studio/examples')->assertNotFound();
        $this->get('/video-demo/studio')->assertDontSee('See examples');
    }
}
