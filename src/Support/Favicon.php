<?php

namespace Ogunsakin01\VideoDemo\Support;

/**
 * The icon the studio and library show in the browser tab: the app's own
 * favicon, so the pages look like part of the app.
 */
class Favicon
{
    /**
     * Files looked for in the app's public folder, best first.
     */
    protected const CANDIDATES = [
        'favicon.svg' => 'image/svg+xml',
        'favicon.ico' => 'image/x-icon',
        'favicon.png' => 'image/png',
        'apple-touch-icon.png' => 'image/png',
    ];

    /**
     * Get the favicon's address and type, or null when the app has none.
     *
     * @return array{href: string, type: ?string}|null
     */
    public static function find(): ?array
    {
        if ($configured = config('video-demo.ui.favicon')) {
            return ['href' => (string) $configured, 'type' => null];
        }

        foreach (self::CANDIDATES as $file => $type) {
            if (is_file(public_path($file))) {
                return ['href' => asset($file).'?v='.filemtime(public_path($file)), 'type' => $type];
            }
        }

        return null;
    }
}
