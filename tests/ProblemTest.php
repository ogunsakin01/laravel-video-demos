<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Studio\Problem;

/**
 * A video's error split into a headline the studio shows whole and the
 * explanation it folds.
 */
class ProblemTest extends TestCase
{
    public function test_the_part_before_the_first_colon_is_the_headline(): void
    {
        $this->assertSame([
            "The AI couldn't find this in the app, so nothing was recorded.",
            "I couldn't find any way to shortlist applicants: the app has three routes.",
        ], Problem::split("The AI couldn't find this in the app, so nothing was recorded: i couldn't find any way to shortlist applicants: the app has three routes."));
    }

    public function test_an_error_without_a_fitting_colon_is_shown_whole(): void
    {
        // No colon, one too soon to make a headline, one too late, and nothing after it.
        foreach ([
            'The worker stopped before it finished.',
            'Failed: the browser closed.',
            'Step 3 (click): Nothing to click.',
            str_repeat('A long sentence without a break ', 6).': and then more.',
            'The AI couldn\'t find this in the app, so nothing was recorded: ',
        ] as $error) {
            $this->assertSame([trim($error), ''], Problem::split($error));
        }
    }
}
