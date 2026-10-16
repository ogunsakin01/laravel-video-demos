<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * What changed in each built-in style, from its settings before to after:
     * the outline on what is being used for the tutorials, and for the
     * marketing styles the camera on each step, music and two-line captions.
     */
    protected const CHANGES = [
        'clean' => [
            'from' => [
                'direction' => 'Calm and clear. Open with a title card that says what the viewer will be able to do, explain each part of the flow with a short caption, and close with a card that sums up the result.',
                'zoom' => false,
                'highlight' => false,
                'music' => null,
            ],
            'to' => [
                'highlight' => true,
            ],
        ],
        'quick' => [
            'from' => [
                'direction' => 'As short as it can be. No title cards. One caption at the start saying what the viewer will learn, then the steps with no extra explanation, ending on the result.',
                'zoom' => false,
                'highlight' => false,
                'music' => null,
            ],
            'to' => [
                'highlight' => true,
            ],
        ],
        'detailed' => [
            'from' => [
                'direction' => 'For people new to the app. Explain why each step matters, point out optional fields and what happens after saving, and start a chapter for each part of the flow.',
                'zoom' => false,
                'highlight' => false,
                'music' => null,
            ],
            'to' => [
                'highlight' => true,
            ],
        ],
        'showcase' => [
            'from' => [
                'direction' => 'For a marketing page. Lead with the benefit to the user, keep captions upbeat but plain, linger on the finished result, and end on a card stating the outcome.',
                'zoom' => false,
                'highlight' => false,
                'music' => null,
            ],
            'to' => [
                'direction' => 'For a marketing page. Lead with the benefit to the user, linger on the finished result, and end on a card stating the outcome. Give each caption a title of under six words naming what the viewer gains, upbeat but plain, and a second line of a few words.',
                'zoom' => true,
                'highlight' => true,
                'music' => 'builtin:corporate',
            ],
        ],
        'ad' => [
            'from' => [
                'direction' => 'A short ad. Open on a card with one bold claim about what the user gains, in under eight words. Cut quickly through the two or three moments that prove it, each with a caption of under six words. Close on a card that names the result and says where to start. No step-by-step explanation.',
                'zoom' => false,
                'highlight' => false,
                'music' => null,
            ],
            'to' => [
                'zoom' => true,
                'highlight' => true,
                'music' => 'builtin:upbeat',
            ],
        ],
        'spotlight' => [
            'from' => [
                'direction' => 'A polished feature reel. Open on a card with one bold line about what the viewer gains. Show each key moment with a caption of under eight words that names the benefit, not the button. Keep the steps that show something worth seeing and skip routine ones. Close on a card naming the result.',
                'zoom' => true,
                'highlight' => true,
                'music' => null,
            ],
            'to' => [
                'direction' => 'A polished feature reel. Open on a card with one bold line about what the viewer gains. Show each key moment with a caption: a title of under six words that names the benefit, not the button, and a second line of a few words. Keep the steps that show something worth seeing and skip routine ones. Close on a card naming the result.',
            ],
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only a built-in style that is still as the package shipped it: one
        // the app changed, in the studio or in its own config, is left alone.
        foreach (self::CHANGES as $key => $change) {
            $style = DB::table('video_demo_styles')->where('key', $key)->first();
            if ($style && $this->matches($style, $change['from'])) {
                DB::table('video_demo_styles')->where('key', $key)->update($change['to']);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::CHANGES as $key => $change) {
            $style = DB::table('video_demo_styles')->where('key', $key)->first();
            if ($style && $this->matches($style, [...$change['from'], ...$change['to']])) {
                DB::table('video_demo_styles')->where('key', $key)->update(array_intersect_key($change['from'], $change['to']));
            }
        }
    }

    /**
     * Determine if a style's saved settings are the given ones.
     */
    protected function matches(object $style, array $settings): bool
    {
        foreach ($settings as $column => $value) {
            $saved = $style->{$column} ?? null;
            if (is_bool($value) ? (bool) $saved !== $value : $saved !== $value) {
                return false;
            }
        }

        return true;
    }
};
