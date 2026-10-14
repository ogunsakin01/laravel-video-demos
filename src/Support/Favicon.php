<?php

namespace Ogunsakin01\VideoDemo\Support;

/**
 * The icon the studio and library show in the browser tab: the package's
 * own icon, the app's favicon, or an image of your choice.
 */
class Favicon
{
    /**
     * The package's own icon: a play button on a near-black square, light in dark mode.
     */
    public const OWN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><style>@media (prefers-color-scheme: dark) { rect { fill: #faf8f3 } path { fill: #171717; stroke: #171717 } }</style><rect width="32" height="32" rx="7" fill="#171717"/><path d="M12.5 9.5v13l10.5-6.5z" fill="#faf8f3" stroke="#faf8f3" stroke-width="1.5" stroke-linejoin="round"/></svg>';

    /**
     * Files looked for in the app's public folder, best first.
     */
    protected const CANDIDATES = [
        'favicon.svg' => 'image/svg+xml',
        'favicon.ico' => 'image/x-icon',
        'favicon.png' => 'image/png',
        'favicon.webp' => 'image/webp',
        'favicon.jpg' => 'image/jpeg',
        'favicon.jpeg' => 'image/jpeg',
        'apple-touch-icon.png' => 'image/png',
    ];

    /**
     * Get the favicon's address and type.
     *
     * The ui.favicon setting chooses it: empty for the package's own icon,
     * "app" for the app's favicon, or the address of any image.
     *
     * @return array{href: string, type: ?string}
     */
    public static function find(): array
    {
        $configured = (string) config('video-demo.ui.favicon');

        if ($configured === 'app') {
            return self::app() ?? self::own();
        }

        if ($configured !== '') {
            return ['href' => $configured, 'type' => null];
        }

        return self::own();
    }

    /**
     * Get the package's own icon.
     *
     * @return array{href: string, type: string}
     */
    public static function own(): array
    {
        return ['href' => 'data:image/svg+xml,'.rawurlencode(self::OWN), 'type' => 'image/svg+xml'];
    }

    /**
     * Get the app's favicon from its public folder, or null when it has none.
     *
     * @return array{href: string, type: string}|null
     */
    public static function app(): ?array
    {
        foreach (self::CANDIDATES as $file => $type) {
            if (is_file(public_path($file))) {
                return ['href' => asset($file).'?v='.filemtime(public_path($file)), 'type' => $type];
            }
        }

        return null;
    }
}
