<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Ai\VideoStopped;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;

/**
 * A video's versions (screens) rehearsed and recorded up to
 * video-demo.recorder.parallel_versions at once, each on its own copy of the
 * sample database, stopping at the first that fails. The browser is faked:
 * each script "runs" for a few checks and leaves the result its version gets.
 */
class ParallelVersionsTest extends TestCase
{
    protected string $root;

    /** @var list<string> */
    public static array $copies = [];

    /** @var list<string> */
    public static array $deleted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/video-demo-parallel-'.uniqid();
        static::$copies = static::$deleted = [];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /**
     * Fake the browser: each version's script runs for $checks[device] checks
     * (2 by default), and fails when its device is in $failing.
     *
     * @param  array<string, int>  $checks
     * @param  list<string>  $failing
     */
    protected function fakeBrowser(array $checks = [], array $failing = []): void
    {
        Process::fake(function (PendingProcess $process) use ($checks, $failing) {
            $dir = dirname((string) $process->command[2]);
            $device = basename($dir);
            File::put("$dir/result.json", json_encode(in_array($device, $failing, true)
                ? ['ok' => false, 'failedStep' => 1, 'error' => "Nothing to click on $device."]
                : ['ok' => true, 'durationMs' => 3000]));

            return Process::describe()->iterations($checks[$device] ?? 2);
        });
    }

    /**
     * A recorder on a sample database whose copies note when they are made and deleted.
     */
    protected function recorder(): Recorder
    {
        $recorder = new Recorder;
        $recorder->sandbox = new class(sys_get_temp_dir().'/1-parallelbase1.sqlite') extends Sandbox
        {
            public function copy(): Sandbox
            {
                $path = sys_get_temp_dir().'/1-parallelcopy'.count(ParallelVersionsTest::$copies).'.sqlite';
                ParallelVersionsTest::$copies[] = $path;

                return new class($path) extends Sandbox
                {
                    public function delete(): void
                    {
                        ParallelVersionsTest::$deleted[] = $this->path;
                    }

                    public function appErrors(int $limit = 20): array
                    {
                        return [['message' => "An error on {$this->path}"]];
                    }
                };
            }
        };

        return $recorder;
    }

    /**
     * @param  list<string>  $devices
     * @return array<string, string>
     */
    protected function dirs(array $devices): array
    {
        return array_combine($devices, array_map(fn ($device) => "$this->root/$device", $devices));
    }

    protected function plan(): Plan
    {
        return Plan::fromArray(['title' => 'Post a job', 'summary' => '', 'steps' => [['action' => 'goto', 'url' => '/jobs/create']]]);
    }

    /**
     * The most versions that were running at once, from the order they started and finished.
     *
     * @param  list<string>  $events
     */
    protected function mostAtOnce(array $events): int
    {
        $running = $most = 0;
        foreach ($events as $event) {
            $running += str_starts_with($event, 'start') ? 1 : -1;
            $most = max($most, $running);
        }

        return $most;
    }

    public function test_versions_are_recorded_side_by_side_up_to_the_limit(): void
    {
        // Desktop takes longest, so the others finish first.
        $this->fakeBrowser(['desktop' => 4, 'tablet' => 1, 'mobile' => 1]);
        $events = [];

        $results = $this->recorder()->recordVersions($this->plan(), $this->dirs(['desktop', 'tablet', 'mobile']), parallel: 2,
            started: function (string $device) use (&$events) {
                $events[] = "start $device";
            },
            finished: function (string $device) use (&$events) {
                $events[] = "finish $device";
            },
        );

        // Two at once: mobile starts when tablet is done, while desktop is still recording.
        $this->assertSame(['start desktop', 'start tablet', 'finish tablet', 'start mobile', 'finish mobile', 'finish desktop'], $events);
        $this->assertSame(2, $this->mostAtOnce($events));
        $this->assertSame(['tablet', 'mobile', 'desktop'], array_keys($results));
        $this->assertTrue(collect($results)->every(fn ($result) => $result->ok));
        $this->assertSame('desktop', $results['desktop']->device);
        Process::assertRanTimes(fn (PendingProcess $process) => str_ends_with((string) $process->command[1], 'record.cjs'), 3);

        // Each on its own copy of the sample database, each written into its job, and all deleted.
        $this->assertCount(3, static::$copies);
        $this->assertEqualsCanonicalizing(static::$copies, static::$deleted);
        $cookies = array_map(fn ($device) => json_decode(File::get("$this->root/$device/job.json"), true)['cookies'][0]['value'] ?? null, ['desktop', 'tablet', 'mobile']);
        $this->assertCount(3, array_unique(array_filter($cookies)));
    }

    public function test_the_first_version_that_fails_stops_the_rest(): void
    {
        // Tablet fails while desktop is still being played; mobile never starts.
        $this->fakeBrowser(['desktop' => 6, 'tablet' => 1], failing: ['tablet']);
        $recorder = $this->recorder();
        $started = [];

        $results = $recorder->verifyVersions($this->plan(), $this->dirs(['desktop', 'tablet', 'mobile']), parallel: 2,
            started: function (string $device) use (&$started) {
                $started[] = $device;
            },
        );

        $this->assertSame(['desktop', 'tablet'], $started);
        $this->assertSame(['tablet'], array_keys($results));
        $this->assertFalse($results['tablet']->ok);
        $this->assertSame('Nothing to click on tablet.', $results['tablet']->error);
        // The app's errors come from the failed version's own copy.
        $this->assertSame([['message' => 'An error on '.static::$copies[1]]], $recorder->versionErrors);
        // Desktop's copy is deleted too, though it never finished.
        $this->assertEqualsCanonicalizing(static::$copies, static::$deleted);
    }

    public function test_other_work_runs_while_the_versions_play(): void
    {
        foreach ([2, 1] as $parallel) {
            static::$copies = static::$deleted = [];
            $this->fakeBrowser(['desktop' => 3, 'mobile' => 1]);
            $events = [];

            $results = $this->recorder()->verifyVersions($this->plan(), $this->dirs(['desktop', 'mobile']), parallel: $parallel,
                started: function (string $device) use (&$events) {
                    $events[] = "start $device";
                },
                finished: function (string $device) use (&$events) {
                    $events[] = "finish $device";
                },
                meanwhile: function () use (&$events) {
                    $events[] = 'meanwhile';
                },
            );

            // Run once, after the first versions started and before any finished.
            $this->assertSame($parallel === 2
                ? ['start desktop', 'start mobile', 'meanwhile', 'finish mobile', 'finish desktop']
                : ['start desktop', 'meanwhile', 'finish desktop', 'start mobile', 'finish mobile'], $events);
            $this->assertCount(2, $results);
            $this->assertEqualsCanonicalizing(static::$copies, static::$deleted);
        }
    }

    public function test_stop_ends_every_version(): void
    {
        $this->fakeBrowser(['desktop' => 50, 'mobile' => 50]);
        $asked = 0;

        try {
            $this->recorder()->recordVersions($this->plan(), $this->dirs(['desktop', 'mobile']), parallel: 2, shouldStop: function () use (&$asked) {
                return ++$asked > 1;
            });
            $this->fail('Expected the recording to stop.');
        } catch (VideoStopped $e) {
            $this->assertSame('Stopped by you.', $e->getMessage());
        }

        // Both were running, and both copies are gone.
        $this->assertCount(2, static::$copies);
        $this->assertEqualsCanonicalizing(static::$copies, static::$deleted);
    }

    public function test_every_screen_at_once_is_the_default_and_one_turns_it_off(): void
    {
        // Every screen at once, up to 4, unless the app says otherwise.
        $shipped = (include __DIR__.'/../config/video-demo.php')['recorder']['parallel_versions'];
        $this->assertSame(4, $shipped);
        config(['video-demo.recorder.parallel_versions' => $shipped]);
        $this->assertSame(3, Recorder::parallelVersions(3));
        // VIDEO_DEMO_PARALLEL_VERSIONS=1 (or false) records them one at a time.
        config(['video-demo.recorder.parallel_versions' => 1]);
        $this->assertSame(1, Recorder::parallelVersions(3));
        config(['video-demo.recorder.parallel_versions' => (int) false]);
        $this->assertSame(1, Recorder::parallelVersions(3));
        config(['video-demo.recorder.parallel_versions' => 2]);
        $this->assertSame(2, Recorder::parallelVersions(3));
        // Never more than there are versions, or than 4.
        $this->assertSame(1, Recorder::parallelVersions(1));
        config(['video-demo.recorder.parallel_versions' => 10]);
        $this->assertSame(4, Recorder::parallelVersions(6));

        // One at a time, a failure stops the next version from starting.
        config(['video-demo.recorder.parallel_versions' => 1]);
        $this->fakeBrowser(failing: ['desktop']);
        $started = [];
        $results = $this->recorder()->verifyVersions($this->plan(), $this->dirs(['desktop', 'mobile']),
            started: function (string $device) use (&$started) {
                $started[] = $device;
            },
        );
        $this->assertSame(['desktop'], $started);
        $this->assertFalse($results['desktop']->ok);
        $this->assertEqualsCanonicalizing(static::$copies, static::$deleted);
    }
}
