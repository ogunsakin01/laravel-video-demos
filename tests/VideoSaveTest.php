<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use PDO;

/**
 * A video's progress is saved while the app writes to the same SQLite file
 * (its sessions, cache and queue): a save that finds the file locked waits
 * a moment and tries again, instead of failing the video.
 */
class VideoSaveTest extends TestCase
{
    public function test_a_save_that_finds_sqlite_locked_tries_again(): void
    {
        $file = sys_get_temp_dir().'/video-demo-locked-'.uniqid().'.sqlite';
        touch($file);
        config(['database.connections.shared' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 1]]);

        try {
            Artisan::call('migrate', ['--database' => 'shared', '--path' => realpath(__DIR__.'/../database/migrations'), '--realpath' => true, '--force' => true]);
            $video = (new Video)->setConnection('shared');
            $video->forceFill(['prompt' => 'Post a job', 'target_seconds' => 60, 'model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'status' => VideoStatus::Planning])->save();

            // Another connection to the file, about to write, as the app's session store is. The save
            // gives up at once (busy_timeout 1ms), as SQLite does when waiting couldn't help.
            $other = new PDO("sqlite:$file");
            $other->exec('BEGIN IMMEDIATE');
            // It lets go before the save's second try.
            $tries = 0;
            DB::connection('shared')->beforeExecuting(function (string $sql) use (&$tries, $other) {
                if (str_starts_with($sql, 'select') && ++$tries === 2) {
                    $other->exec('COMMIT');
                }
            });
            $saved = $video->changeSaved('log', fn (array $log) => [...$log, ['message' => 'Recorded 104.0 seconds (Mobile)']]);

            $this->assertTrue($saved);
            $this->assertSame(2, $tries);
            $this->assertSame('Recorded 104.0 seconds (Mobile)', Video::on('shared')->find($video->id)->log[0]['message']);
        } finally {
            unset($other);
            app('db')->purge('shared');
            File::delete($file);
        }
    }
}
