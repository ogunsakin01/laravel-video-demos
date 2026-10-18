<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Support\Paths;

/**
 * Paths given on Windows (C:\app, \\server\share) are absolute too, and are
 * compared with forward slashes.
 */
class PathsTest extends TestCase
{
    public function test_absolute_paths_on_any_system(): void
    {
        foreach (['/var/app', 'C:\\app\\docs', 'd:/app', '\\\\server\\share'] as $path) {
            $this->assertTrue(Paths::isAbsolute($path), $path);
        }
        foreach (['docs/out', '../web', 'C:file', ''] as $path) {
            $this->assertFalse(Paths::isAbsolute($path), $path);
        }
    }

    public function test_relative_paths_are_from_the_app_or_a_folder(): void
    {
        $this->assertSame('D:\\a\\out', Paths::fromBase('D:\\a\\out'));
        $this->assertSame(base_path('docs/out'), Paths::fromBase('docs/out'));
        $this->assertSame('C:\\app/../web', Paths::fromBase('../web', 'C:\\app\\'));
        $this->assertSame('C:/app/src/Jobs', Paths::slashes('C:\\app\\src\\Jobs'));
    }
}
