<?php

namespace Ogunsakin01\VideoDemo\Ai;

use RuntimeException;

/**
 * Thrown when the model cannot produce a usable plan or answer.
 */
class PlanningFailed extends RuntimeException {}
