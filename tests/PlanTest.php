<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Recording\Plan;

class PlanTest extends TestCase
{
    protected function step(string $action, array $fields = []): array
    {
        return [
            'action' => $action, 'why' => '', 'url' => null, 'target' => null, 'value' => null,
            'title' => null, 'text' => null, 'ms' => null, 'chapter' => null, ...$fields,
        ];
    }

    protected function plan(): Plan
    {
        return new Plan('Post a job', 'Shows posting a job.', [
            $this->step('card', ['title' => 'Post a job', 'ms' => 3500]),
            $this->step('goto', ['url' => '/jobs/create', 'chapter' => 'Open the form']),
            $this->step('type', ['target' => ['label' => 'Title'], 'value' => 'Senior Laravel Developer', 'chapter' => 'Fill it in']),
            $this->step('caption', ['title' => 'Add the details', 'ms' => 3000]),
            $this->step('click', ['target' => ['role' => 'button', 'name' => 'Post job']]),
            $this->step('pause', ['ms' => 1500]),
        ]);
    }

    public function test_fit_to_stretches_flexible_steps_towards_the_target(): void
    {
        $plan = $this->plan();
        $before = $plan->estimatedSeconds();

        $longer = $plan->fitTo((int) ceil($before * 1.5));
        $this->assertEqualsWithDelta($before * 1.5, $longer->estimatedSeconds(), 1.0);

        // Never stretched past three times, however long the target
        $capped = $plan->fitTo(600);
        $this->assertSame(3500 * 3, $capped->steps[0]['ms']);

        // Actions keep their own speed
        $this->assertNull($capped->steps[4]['ms']);
    }

    public function test_chapters_come_from_steps(): void
    {
        $chapters = $this->plan()->chapters();

        $this->assertSame(['Open the form', 'Fill it in'], array_column($chapters, 'title'));
        $this->assertSame(3.5, $chapters[0]['at']);
    }

    public function test_schema_is_strict_compatible(): void
    {
        $check = function (array $schema) use (&$check) {
            if (($schema['type'] ?? null) === 'object' || (is_array($schema['type'] ?? null) && in_array('object', $schema['type']))) {
                $this->assertFalse($schema['additionalProperties']);
                $this->assertEqualsCanonicalizing(array_keys($schema['properties']), $schema['required']);
                array_map($check, $schema['properties']);
            }
            if (isset($schema['items'])) {
                $check($schema['items']);
            }
            array_map($check, $schema['anyOf'] ?? []);
        };

        $check(Plan::schema());
    }

    public function test_planner_rejects_incomplete_steps(): void
    {
        $planner = new Planner(new Drivers, new CodeTools);

        $this->assertSame([], $planner->problems($this->plan()));

        $problems = $planner->problems(new Plan('x', 'y', [
            $this->step('goto'),
            $this->step('click', ['target' => ['role' => null, 'name' => null, 'label' => null, 'placeholder' => null, 'text' => null, 'selector' => null, 'frame' => 'iframe']]),
            $this->step('type', ['target' => ['label' => 'Title']]),
            $this->step('goto', ['url' => 'https://example.com/jobs']),
        ]));

        $this->assertSame([
            'Step 1 (goto) needs url.',
            'Step 2 (click) needs target.',
            'Step 3 (type) needs value.',
            'Step 4: use a path such as /jobs, not a full URL.',
        ], $problems);
    }

    public function test_steps_can_be_limited_to_some_versions(): void
    {
        $plan = new Plan('x', 'y', [
            $this->step('goto', ['url' => '/']),
            $this->step('click', ['target' => ['role' => 'button', 'name' => 'Menu'], 'only_on' => ['mobile', 'tablet']]),
            $this->step('click', ['target' => ['role' => 'link', 'name' => 'Jobs']]),
        ]);

        $this->assertCount(2, $plan->forDevice('desktop')->steps);
        $this->assertCount(3, $plan->forDevice('mobile')->steps);
        $this->assertSame(['desktop', 'tv', 'tablet', 'mobile'], Plan::schema()['properties']['steps']['items']['properties']['only_on']['items']['enum']);
    }

    public function test_at_double_speed_actions_are_quicker_but_reading_time_is_kept(): void
    {
        $plan = $this->plan();
        $normal = $plan->estimatedSeconds();
        $fast = $plan->estimatedSeconds(2);

        $reading = (3500 + 3000 + 1500) / 1000;
        $this->assertEqualsWithDelta($reading + ($normal - $reading) / 2, $fast, 0.1);

        // Fitting to a length at 2x leaves more room for captions and cards
        $this->assertGreaterThan($plan->fitTo(12)->steps[0]['ms'], $plan->fitTo(12, 2)->steps[0]['ms']);
    }
}
