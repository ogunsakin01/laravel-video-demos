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
        // The five defaults from config, plus this one.
        $this->assertSame(6, VideoStyle::count());
        $this->assertSame('#0f766e', $added->accent);
        $this->assertSame('abstract', $added->art);
        $this->assertSame('abstract', $added->snapshot()['art']);

        $this->put("/video-demo/studio/styles/{$added->id}", [...$style, 'pace' => 'brisk'])->assertRedirect();
        $this->assertSame('brisk', $added->fresh()->pace);

        $this->post('/video-demo/studio/styles', [...$style, 'accent' => 'teal'])->assertSessionHasErrors('accent');

        $this->delete("/video-demo/studio/styles/{$added->id}")->assertRedirect();
        $this->assertSame(5, VideoStyle::count());
        $this->assertSame('Promo ad', VideoStyle::where('key', 'ad')->value('name'));
    }

    public function test_the_last_style_cannot_be_deleted(): void
    {
        config(['video-demo.styles' => ['only' => ['name' => 'Only', 'direction' => 'x']]]);
        $only = VideoStyle::options()->first();

        $this->delete("/video-demo/studio/styles/{$only->id}")->assertSessionHas('error');
        $this->assertSame(1, VideoStyle::count());
    }
}
