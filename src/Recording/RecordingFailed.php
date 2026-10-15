<?php

namespace Ogunsakin01\VideoDemo\Recording;

use RuntimeException;

/**
 * Thrown when a version couldn't be recorded, carrying what the recorder saw
 * (the step, the page, the browser's errors) for the full error view.
 */
class RecordingFailed extends RuntimeException
{
    public function __construct(string $message, public readonly RecordingResult $result, public readonly ?array $step = null)
    {
        parent::__construct($message);
    }
}
