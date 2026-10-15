<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Recording\Timeline;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Tests how a recording becomes the video: waits on the app cut out, except
 * while the voice-over speaks, and times mapped into the finished video.
 */
class TimelineTest extends BaseTestCase
{
    public function test_waits_are_cut_but_the_press_and_the_result_are_kept(): void
    {
        // A 10 s recording with a 3 s page load from 4 s to 7 s.
        $timeline = Timeline::build(10_000, 1, [[4000, 7000]]);

        $this->assertSame([[0.0, 4150.0], [6750.0, 10_000.0]], $timeline->keep);
        $this->assertTrue($timeline->hasCuts());
        $this->assertSame(7.4, $timeline->seconds());
        // Before the wait, unchanged; after it, 2.6 s earlier; inside it, where the cut is.
        $this->assertSame(2.0, $timeline->at(2000));
        $this->assertSame(5.4, $timeline->at(8000));
        $this->assertSame(4.15, $timeline->at(5000));
    }

    public function test_short_waits_and_waits_under_a_spoken_line_are_kept(): void
    {
        $this->assertFalse(Timeline::build(10_000, 1, [[4000, 4500]])->hasCuts());

        // A line spoken from 3 s to 6 s keeps that part of a 2 s to 9 s wait.
        $timeline = Timeline::build(12_000, 1, [[2000, 9000]], [[3000, 6000]]);
        $this->assertSame([[0.0, 2150.0], [3000.0, 6000.0], [8750.0, 12_000.0]], $timeline->keep);
        // The line keeps its full three seconds.
        $this->assertEqualsWithDelta(3.0, $timeline->at(6000) - $timeline->at(3000), 0.001);
    }

    public function test_overlapping_waits_join_and_speed_applies_to_what_is_kept(): void
    {
        $timeline = Timeline::build(10_000, 2, [[1000, 3000], [2500, 5000], [5000, 5100]]);

        $this->assertSame([[0.0, 1150.0], [4850.0, 10_000.0]], $timeline->keep);
        $this->assertSame(3.15, $timeline->seconds());
        $this->assertEquals($timeline, Timeline::fromArray($timeline->toArray()));
    }
}
