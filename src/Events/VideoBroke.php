<?php

namespace Ogunsakin01\VideoDemo\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Ogunsakin01\VideoDemo\Models\Video;

/**
 * Fired when a check finds that a video's plan no longer works in the app,
 * such as after a button was renamed, so an app can tell someone.
 * $result says where it failed: device, step, error and url.
 */
class VideoBroke
{
    use Dispatchable;

    /**
     * Create a new event instance.
     *
     * @param  array<string, mixed>  $result
     */
    public function __construct(public Video $video, public array $result) {}
}
