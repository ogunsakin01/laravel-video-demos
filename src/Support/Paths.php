<?php

namespace Ogunsakin01\VideoDemo\Support;

/**
 * File paths that work the same on Windows: absolute paths there start with a
 * drive (C:\ or C:/) or \\server, and their parts are joined with
 * backslashes, which PHP also accepts as forward slashes.
 */
final class Paths
{
    /**
     * Determine if a path is absolute, on any system.
     */
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    /**
     * Get a path as it is when absolute, otherwise from the app's root (or the given folder).
     */
    public static function fromBase(string $path, ?string $base = null): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        return $base === null ? base_path($path) : rtrim($base, '/\\').'/'.$path;
    }

    /**
     * Get a path with forward slashes only, so it can be compared and shown the same way everywhere.
     */
    public static function slashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
