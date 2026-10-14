<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Ai\CostEstimator;
use Ogunsakin01\VideoDemo\Ai\Pricing;

/**
 * Tests the cost estimates shown before a video is made, and the actual cost after.
 */
class CostEstimatorTest extends TestCase
{
    public function test_range_is_ordered_and_grows_with_length(): void
    {
        $estimator = new CostEstimator;
        $short = $estimator->estimate('claude-opus-5-5', 'high', 30, 80);
        $long = $estimator->estimate('claude-opus-5-5', 'high', 180, 80);

        $this->assertLessThan($short['expected'], $short['low']);
        $this->assertLessThan($short['high'], $short['expected']);
        $this->assertGreaterThan($short['expected'], $long['expected']);
    }

    public function test_cheaper_models_and_lower_effort_cost_less(): void
    {
        $estimator = new CostEstimator;
        $opus = $estimator->estimate('claude-opus-5-5', 'high', 60, 80)['expected'];
        $sonnet = $estimator->estimate('claude-sonnet-5-5', 'high', 60, 80)['expected'];
        $haiku = $estimator->estimate('claude-haiku-5-5', 'high', 60, 80)['expected'];
        $opusLow = $estimator->estimate('claude-opus-5-5', 'low', 60, 80)['expected'];

        $this->assertGreaterThan($sonnet, $opus);
        $this->assertGreaterThan($haiku, $sonnet);
        $this->assertGreaterThan($opusLow, $opus);
        // A one-minute video on Opus at high effort should be well under a dollar.
        $this->assertLessThan(1.0, $opus);
        $this->assertGreaterThan(0.05, $opus);
    }

    public function test_actual_cost_uses_cache_prices(): void
    {
        // 1M of each kind of token on Opus 5.5: 4 + 5 (writes at 1.25×) + 0.20 + 20.
        $this->assertEqualsWithDelta(29.20, Pricing::cost('claude-opus-5-5', 1_000_000, 1_000_000, 1_000_000, 1_000_000), 0.0001);
    }
}
