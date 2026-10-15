<?php

namespace Ogunsakin01\VideoDemo\Studio;

/**
 * Sample videos made with this package, for the studio's Examples page. The
 * files live in the package's GitHub repository, not in installs, and are
 * streamed from jsDelivr pinned to a release tag, so they never change.
 */
class Examples
{
    /**
     * The jsDelivr address of the sample videos, pinned to a release.
     */
    public const DEFAULT_URL = 'https://cdn.jsdelivr.net/gh/ogunsakin01/laravel-video-demos@v0.15.0';

    /**
     * Determine if the Examples page should be shown.
     */
    public static function enabled(): bool
    {
        return (bool) config('video-demo.ui.examples', true) && static::baseUrl() !== '';
    }

    /**
     * Get the base URL the sample videos are streamed from.
     */
    public static function baseUrl(): string
    {
        return rtrim((string) config('video-demo.ui.examples_url', self::DEFAULT_URL), '/');
    }

    /**
     * Get the sample videos: the same short video made in each built-in style.
     *
     * @return list<array{key: string, title: string, about: string, style: string, style_key: string, versions: array<string, array{video: string, poster: string, seconds: int}>}>
     */
    public static function all(): array
    {
        $file = fn (string $path) => static::baseUrl().'/'.$path;
        $version = fn (string $name, int $seconds) => ['video' => $file("$name.mp4"), 'poster' => $file("$name.jpg"), 'seconds' => $seconds];

        return [
            [
                'key' => 'clean',
                'title' => 'Clean walkthrough',
                'about' => 'Calm and clear: an opening card, a short caption for each part of the flow, and a closing card.',
                'style' => 'Clean walkthrough',
                'style_key' => 'clean',
                'versions' => ['desktop' => $version('docs/examples/example-clean', 29)],
            ],
            [
                'key' => 'quick',
                'title' => 'Quick tip',
                'about' => 'As short as it can be: no title cards, one caption to set it up, then straight through the steps.',
                'style' => 'Quick tip',
                'style_key' => 'quick',
                'versions' => ['desktop' => $version('docs/examples/example-quick', 18)],
            ],
            [
                'key' => 'detailed',
                'title' => 'Detailed tutorial',
                'about' => 'For people new to the app: captions explain why each step matters and what happens after saving.',
                'style' => 'Detailed tutorial',
                'style_key' => 'detailed',
                'versions' => ['desktop' => $version('docs/examples/example-detailed', 49)],
            ],
            [
                'key' => 'showcase',
                'title' => 'Product showcase',
                'about' => 'For a marketing page: dark title cards with line art, upbeat captions at the top, and a strong finish.',
                'style' => 'Product showcase',
                'style_key' => 'showcase',
                'versions' => ['desktop' => $version('docs/examples/example-showcase', 29)],
            ],
            [
                'key' => 'ad',
                'title' => 'Promo ad',
                'about' => 'A short ad: bold title cards with line art that draws itself on, and captions of a few words.',
                'style' => 'Promo ad',
                'style_key' => 'ad',
                'versions' => ['desktop' => $version('docs/examples/example-ad', 19)],
            ],
            [
                'key' => 'spotlight',
                'title' => 'Spotlight',
                'about' => 'The app in dark mode, with the camera zooming in on each step and an outline around what is being used.',
                'style' => 'Spotlight',
                'style_key' => 'spotlight',
                'versions' => ['desktop' => $version('docs/examples/example-spotlight', 32)],
            ],
            [
                'key' => 'promo',
                'title' => 'Promo reel',
                'about' => 'Like a product promo: big two-line headlines that rise in, the app in dark mode with the camera on each step, at a brisk pace.',
                'style' => 'Promo reel',
                'style_key' => 'promo',
                'versions' => ['desktop' => $version('docs/examples/example-promo', 30)],
            ],
        ];
    }
}
