<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Models\VideoStyle;

/**
 * Tests video styles: the defaults from config and the ones developers add.
 */
class StyleTest extends TestCase
{
    public function test_defaults_come_from_config_once(): void
    {
        config(['video-demo.styles' => [
            'house' => ['name' => 'House style', 'direction' => 'Plain and short.', 'theme' => 'dark'],
        ]]);

        $styles = VideoStyle::options();
        VideoStyle::options();

        $this->assertCount(1, $styles);
        $this->assertSame(1, VideoStyle::count());
        $this->assertSame('House style', $styles->first()->name);
        // Settings a config entry leaves out fall back to the defaults.
        $this->assertSame('normal', $styles->first()->pace);
    }

    public function test_developers_can_add_edit_and_delete_styles(): void
    {
        $style = [
            'name' => 'Onboarding series', 'direction' => 'Friendly. Address the viewer as you.',
            'theme' => 'light', 'accent' => '#0f766e', 'captions' => 'top', 'pace' => 'relaxed', 'art' => 'abstract',
        ];

        $this->post('/video-demo/studio/styles', $style)->assertRedirect('/video-demo/studio/styles');
        $added = VideoStyle::where('name', 'Onboarding series')->firstOrFail();
        // The seven defaults from config, plus this one.
        $this->assertSame(8, VideoStyle::count());
        $this->assertSame('#0f766e', $added->accent);
        $this->assertSame('abstract', $added->art);
        $this->assertSame('abstract', $added->snapshot()['art']);

        $this->put("/video-demo/studio/styles/{$added->id}", [...$style, 'pace' => 'brisk'])->assertRedirect();
        $this->assertSame('brisk', $added->fresh()->pace);

        $this->post('/video-demo/studio/styles', [...$style, 'accent' => 'teal'])->assertSessionHasErrors('accent');

        $this->delete("/video-demo/studio/styles/{$added->id}")->assertRedirect();
        $this->assertSame(7, VideoStyle::count());
        $this->assertSame('Promo ad', VideoStyle::where('key', 'ad')->value('name'));
    }

    public function test_the_last_style_cannot_be_deleted(): void
    {
        config(['video-demo.styles' => ['only' => ['name' => 'Only', 'direction' => 'x']]]);
        $only = VideoStyle::options()->first();

        $this->delete("/video-demo/studio/styles/{$only->id}")->assertSessionHas('error');
        $this->assertSame(1, VideoStyle::count());
    }

    public function test_spotlight_is_a_built_in_style_with_zoom_and_highlight(): void
    {
        $spotlight = VideoStyle::options()->firstWhere('key', 'spotlight');
        $this->assertSame(['dark', true, true], [$spotlight->app_theme, $spotlight->zoom, $spotlight->highlight]);
        $this->assertSame(['app_theme' => 'dark', 'zoom' => true, 'highlight' => true], array_intersect_key($spotlight->snapshot(), array_flip(['app_theme', 'zoom', 'highlight'])));

        $this->get('/video-demo/studio/styles')->assertSee('Spotlight')->assertSee('Zooms in on each step')->assertSee('App appearance');

        // Promo reel: big headlines over the app in dark mode, zooming in, with upbeat music.
        $promo = VideoStyle::options()->firstWhere('key', 'promo');
        $this->assertSame(['Promo reel', 'headline', 'dark', true, true, 'brisk', 'top', 'builtin:upbeat'], [$promo->name, $promo->art, $promo->app_theme, $promo->zoom, $promo->highlight, $promo->pace, $promo->captions, $promo->music]);
        $this->get('/video-demo/studio/styles')->assertSee('Promo reel')->assertSee('Bold headlines');

        // The settings can be turned on for any style.
        $style = VideoStyle::options()->firstWhere('key', 'clean');
        $this->put("/video-demo/studio/styles/{$style->id}", [
            'name' => 'Clean walkthrough', 'direction' => 'Calm.', 'theme' => 'light', 'accent' => '#171717', 'captions' => 'bottom', 'pace' => 'normal', 'art' => 'none',
            'app_theme' => 'dark', 'zoom' => '1',
        ])->assertRedirect();
        $this->assertSame(['dark', true, false], [$style->fresh()->app_theme, $style->fresh()->zoom, $style->fresh()->highlight]);
    }
}
