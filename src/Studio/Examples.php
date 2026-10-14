<?php

namespace Ogunsakin01\VideoDemo\Studio;

/**
 * Sample videos made with this package, shown on the studio's Examples page
 * so new users can see what they'll get. The files live in the package's
 * GitHub repository (not in installs) and are streamed from jsDelivr, pinned
 * to a release tag so they never change underneath the page.
 */
class Examples
{
    public const DEFAULT_URL = 'https://cdn.jsdelivr.net/gh/ogunsakin01/laravel-video-demos@v0.5.0';

    public static function enabled(): bool
    {
        return (bool) config('video-demo.ui.examples', true) && static::baseUrl() !== '';
    }

    public static function baseUrl(): string
    {
        return rtrim((string) config('video-demo.ui.examples_url', self::DEFAULT_URL), '/');
    }

    /**
     * @return list<array{key: string, title: string, about: string, style: string, style_key: string, versions: array<string, array{video: string, poster: string, seconds: int}>}>
     */
    public static function all(): array
    {
        $file = fn (string $path) => static::baseUrl().'/'.$path;
        $version = fn (string $name, int $seconds) => ['video' => $file("$name.mp4"), 'poster' => $file("$name.jpg"), 'seconds' => $seconds];

        return [
            [
                'key' => 'studio-tour',
                'title' => 'A product tour',
                'about' => 'The studio showing itself off: bold title cards with line art that draws itself on, short captions, and quick cuts through the main features.',
                'style' => 'Promo ad',
                'style_key' => 'ad',
                'versions' => ['desktop' => $version('docs/studio-tour', 65)],
            ],
            [
                'key' => 'library-tour',
                'title' => 'A feature walkthrough, desktop and mobile',
                'about' => 'One plan recorded for two screens. On the phone, the same steps play in the mobile layout, with taps instead of a cursor.',
                'style' => 'Promo ad',
                'style_key' => 'ad',
                'versions' => [
                    'desktop' => $version('docs/library-tour', 57),
                    'mobile' => $version('docs/library-tour-mobile', 58),
                ],
            ],
            [
                'key' => 'post-a-job',
                'title' => 'A how-to, on every screen',
                'about' => 'Filling in and publishing a form, from the opening card to the result, recorded for desktop, TV, tablet and mobile from one plan.',
                'style' => 'Promo ad',
                'style_key' => 'ad',
                'versions' => [
                    'desktop' => $version('workbench/demo-assets/sample', 28),
                    'tv' => $version('workbench/demo-assets/sample-tv', 28),
                    'tablet' => $version('workbench/demo-assets/sample-tablet', 29),
                    'mobile' => $version('workbench/demo-assets/sample-mobile', 28),
                ],
            ],
        ];
    }
}
