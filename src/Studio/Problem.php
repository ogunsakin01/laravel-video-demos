<?php

namespace Ogunsakin01\VideoDemo\Studio;

use Illuminate\Support\Str;

/**
 * Splits a video's error into a short headline and the rest, so the studio can
 * show what happened at a glance and fold the AI's longer explanation.
 */
class Problem
{
    /**
     * How long a headline may be: an error whose first colon comes sooner
     * ("Failed: …", "Step 3 (click): …") or later is shown whole.
     */
    protected const HEADLINE = [30, 140];

    /**
     * Get the headline (the part before the first colon) and the explanation after it.
     *
     * "The AI couldn't find this in the app, so nothing was recorded: I couldn't…"
     * becomes "The AI couldn't find this in the app, so nothing was recorded." and "I couldn't…".
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $error): array
    {
        $error = trim($error);
        $at = strpos($error, ': ');

        if ($at === false || $at < static::HEADLINE[0] || $at > static::HEADLINE[1]) {
            return [$error, ''];
        }

        $explanation = trim(substr($error, $at + 2));

        if ($explanation === '') {
            return [$error, ''];
        }

        return [substr($error, 0, $at).'.', Str::ucfirst($explanation)];
    }
}
