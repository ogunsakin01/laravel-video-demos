<?php

namespace Ogunsakin01\VideoDemo\Recording;

/**
 * Plans a virtual camera over a recording: it eases in on each element a step
 * uses and pulls back out for captions, title cards and new pages. The
 * frames are then drawn zoomed by resources/recorder/camera.cjs.
 */
class Camera
{
    /**
     * Seconds each camera move takes.
     */
    public const MOVE = 0.6;

    /**
     * Seconds the camera starts moving before the step, so it arrives in time.
     */
    public const LEAD = 0.25;

    /**
     * The shortest a zoom may hold. One the next page or caption would undo
     * sooner than this would only jolt, so it is skipped.
     */
    public const HOLD = 1.2;

    /**
     * Get the camera's positions over time: when each move starts, and the
     * zoom and centre it moves to.
     *
     * $focus lists where each step happened, in seconds of the finished video:
     * ['t' => 1.2, 'x' => 100, 'y' => 200, 'w' => 300, 'h' => 40] for an
     * element, or ['t' => 3.4, 'wide' => true] to show the whole page.
     *
     * @param  list<array{t: float, x?: float, y?: float, w?: float, h?: float, wide?: bool}>  $focus
     * @return list<array{s: float, z: float, x: float, y: float}>
     */
    public static function keyframes(array $focus, int $width, int $height, float $maxZoom = 1.8): array
    {
        $keys = [['s' => 0.0, 'z' => 1.0, 'x' => $width / 2.0, 'y' => $height / 2.0]];

        foreach ($focus as $i => $f) {
            $next = $focus[$i + 1] ?? null;
            if (empty($f['wide']) && $next && ! empty($next['wide']) && $next['t'] - $f['t'] < self::HOLD) {
                continue;
            }

            $key = ['s' => max(0.0, (float) $f['t'] - self::LEAD), 'z' => 1.0, 'x' => $width / 2.0, 'y' => $height / 2.0];

            if (empty($f['wide']) && ($f['w'] ?? 0) > 0 && ($f['h'] ?? 0) > 0) {
                // Frame the element with room around it; large elements need little zoom.
                $zoom = min($maxZoom, $width * 0.8 / $f['w'], $height * 0.6 / $f['h']);
                if ($zoom >= 1.15) {
                    $key = ['s' => $key['s'], 'z' => round($zoom, 3), 'x' => (float) $f['x'] + $f['w'] / 2, 'y' => (float) $f['y'] + $f['h'] / 2];
                }
            }

            $last = $keys[array_key_last($keys)];
            if (abs($last['z'] - $key['z']) < 0.01 && abs($last['x'] - $key['x']) < 4 && abs($last['y'] - $key['y']) < 4) {
                continue;
            }

            // One move at a time: a move that starts before the last one ends would jump.
            $key['s'] = round(max($key['s'], $last['s'] + self::MOVE), 3);
            $keys[] = $key;
        }

        return $keys;
    }
}
