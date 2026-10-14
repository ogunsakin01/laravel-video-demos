<?php

namespace Ogunsakin01\VideoDemo\Ai;

/**
 * Estimates what one video will cost before it is made, by simulating the planning
 * conversation: the model reads some code each turn and earlier turns are read back from the cache.
 */
class CostEstimator
{
    // Measured guesses. Tune them against the actual costs your videos show.
    protected const SYSTEM_TOKENS = 3_500;

    protected const TOKENS_PER_ROUTE = 25;

    protected const CODE_TOKENS_PER_TURN = 3_000;

    protected const OUTPUT_TOKENS_PER_TURN = 400;

    protected const TOKENS_PER_STEP = 90;

    protected const SECONDS_PER_STEP = 2.5;

    protected const REPAIR_SNAPSHOT_TOKENS = 4_000;

    /**
     * The thinking tokens per turn, by effort.
     */
    protected const THINKING = ['low' => 300, 'medium' => 900, 'high' => 1_800, 'xhigh' => 3_000, 'max' => 5_000];

    /**
     * Estimate the cost of a video of the given length.
     *
     * The range allows for the code being easier or harder to follow than average, and for
     * one repair round. Prices are null when the model has none in the config.
     *
     * @return array{low: ?float, high: ?float, expected: ?float, turns: int, known: bool}
     */
    public function estimate(string $model, string $effort, int $targetSeconds, int $routeCount): array
    {
        if (! Pricing::known($model)) {
            return ['low' => null, 'expected' => null, 'high' => null, 'turns' => 4 + (int) ceil($targetSeconds / 20) + 1, 'known' => false];
        }

        $thinking = self::THINKING[$effort] ?? self::THINKING['high'];
        $steps = (int) ceil($targetSeconds / self::SECONDS_PER_STEP);
        // Models not marked reliable take more turns to reach the same plan. Calibrated
        // on real runs, where Haiku read about 1.4 times as much code.
        $factor = (Pricing::model($model)['reliable'] ?? true) ? 1.0 : 1.4;
        $turns = min((int) ceil((4 + $targetSeconds / 20) * $factor), (int) config('video-demo.limits.max_turns', 30));

        $usage = new Usage;
        // The first request writes the instructions and route list to the cache.
        $history = self::SYSTEM_TOKENS + $routeCount * self::TOKENS_PER_ROUTE;
        $usage = $usage->plus($this->turn($model, cached: 0, fresh: $history, output: self::OUTPUT_TOKENS_PER_TURN + $thinking));
        $history += self::OUTPUT_TOKENS_PER_TURN;

        // Each later turn reads the history from the cache and adds new code.
        for ($i = 1; $i < $turns; $i++) {
            $usage = $usage->plus($this->turn($model, $history, self::CODE_TOKENS_PER_TURN, self::OUTPUT_TOKENS_PER_TURN + $thinking));
            $history += self::CODE_TOKENS_PER_TURN + self::OUTPUT_TOKENS_PER_TURN;
        }

        $planOutput = $steps * self::TOKENS_PER_STEP + $thinking * 2;
        $usage = $usage->plus($this->turn($model, $history, 0, $planOutput));
        $expected = $usage->cost;

        // One repair: the failure and page snapshot, then a revised plan.
        $repair = $this->turn($model, $history + $planOutput, self::REPAIR_SNAPSHOT_TOKENS, $planOutput)->cost;

        return [
            'low' => round($expected * 0.6, 4),
            'expected' => round($expected + $repair * 0.5, 4),
            'high' => round(($expected + $repair) * 1.5, 4),
            'turns' => $turns + 1,
            'known' => true,
        ];
    }

    /**
     * Get the usage of one simulated request.
     */
    protected function turn(string $model, int $cached, int $fresh, int $output): Usage
    {
        return new Usage(
            cacheWrite: $fresh,
            cacheRead: $cached,
            output: $output,
            cost: Pricing::cost($model, 0, $fresh, $cached, $output),
        );
    }
}
