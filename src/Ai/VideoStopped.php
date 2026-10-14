<?php

namespace Ogunsakin01\VideoDemo\Ai;

use RuntimeException;

/**
 * Thrown when someone presses Stop, so the job ends at its next safe point.
 */
class VideoStopped extends RuntimeException {}
