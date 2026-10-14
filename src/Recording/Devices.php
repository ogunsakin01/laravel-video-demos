<?php

namespace Ogunsakin01\VideoDemo\Recording;

use InvalidArgumentException;

/** The versions a video can be recorded in, from config('video-demo.devices') */
class Devices
{
    /** @return array<string, array{label: string, width: int, height: int, touch: bool}> */
    public static function all(): array
    {
        return array_map(fn ($d) => [
            'label' => $d['label'] ?? '',
            'width' => (int) $d['width'],
            'height' => (int) $d['height'],
            'touch' => (bool) ($d['touch'] ?? false),
        ], config('video-demo.devices', []));
    }

    public static function get(string $key): array
    {
        return static::all()[$key] ?? throw new InvalidArgumentException("Unknown device [$key]. Add it to video-demo.devices.");
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    /** @return list<string> */
    public static function defaults(): array
    {
        return array_values(array_intersect(config('video-demo.default_devices', ['desktop']), static::keys())) ?: [static::keys()[0]];
    }

    /** The version shown first and used for the poster: desktop when there is one */
    public static function primary(array $devices): string
    {
        foreach (['desktop', 'tv', 'tablet', 'mobile'] as $preferred) {
            if (in_array($preferred, $devices, true)) {
                return $preferred;
            }
        }

        return $devices[0] ?? static::defaults()[0];
    }

    /** "Desktop 1280×800" */
    public static function describe(string $key): string
    {
        $d = static::get($key);

        return "{$d['label']} {$d['width']}×{$d['height']}".($d['touch'] ? ', touch' : '');
    }
}
