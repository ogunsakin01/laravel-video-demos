<?php

namespace Ogunsakin01\VideoDemo\Ai;

use RuntimeException;

/** Someone pressed Stop; the job ends at its next safe point */
class VideoStopped extends RuntimeException {}
