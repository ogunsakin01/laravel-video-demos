<?php

namespace Ogunsakin01\VideoDemo\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;

/**
 * Fired whenever a video moves to another status, such as Ready or Failed,
 * so an app can send a notification or update its own screens.
 */
class VideoStatusChanged
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public Video $video, public ?VideoStatus $from, public VideoStatus $to) {}
}
