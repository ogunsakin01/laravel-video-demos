<?php

namespace Ogunsakin01\VideoDemo\Recording;

use InvalidArgumentException;

/**
 * The versions a video can be recorded in, from config('video-demo.devices').
 */
class Devices
{
    /**
     * Get every device, keyed by name.
     *
     * @return array<string, array{label: string, width: int, height: int, touch: bool}>
     */
    public static function all(): array
    {
        return array_map(fn ($d) => [
            'label' => $d['label'] ?? '',
            'width' => (int) $d['width'],
            'height' => (int) $d['height'],
            'touch' => (bool) ($d['touch'] ?? false),
        ], config('video-demo.devices', []));
    }

    /**
     * Get a device by its key.
     *
     * @throws InvalidArgumentException
     */
    public static function get(string $key): array
    {
        return static::all()[$key] ?? throw new InvalidArgumentException("Unknown device [$key]. Add it to video-demo.devices.");
    }

    /**
     * Get the key of every device.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    /**
     * Get the devices a new video is recorded in.
     *
     * Falls back to the first device when none of the configured defaults exist.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return array_values(array_intersect(config('video-demo.default_devices', ['desktop']), static::keys())) ?: [static::keys()[0]];
    }

    /**
     * Get the version shown first and used for the poster, preferring desktop.
     */
    public static function primary(array $devices): string
    {
        foreach (['desktop', 'tv', 'tablet', 'mobile'] as $preferred) {
            if (in_array($preferred, $devices, true)) {
                return $preferred;
            }
        }

        return $devices[0] ?? static::defaults()[0];
    }

    /**
     * Describe a device by its label and size, such as "Desktop 1280×800".
     */
    public static function describe(string $key): string
    {
        $d = static::get($key);

        return "{$d['label']} {$d['width']}×{$d['height']}".($d['touch'] ? ', touch' : '');
    }
}
