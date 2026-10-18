<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Recording\Plan;

/**
 * Tests the plan: its steps, timing and JSON schema.
 */
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

        $longer = $plan->fitTo((int) ceil($before * 1.2));
        $this->assertEqualsWithDelta($before * 1.2, $longer->estimatedSeconds(), 1.0);

        // Never stretched past 1.4 times, however long the target: a video a
        // little short beats one that lingers.
        $capped = $plan->fitTo(600);
        $this->assertSame((int) round(3500 * 1.4), $capped->steps[0]['ms']);

        // Actions keep their own speed.
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

    public function test_a_video_can_sign_in_partway_through(): void
    {
        $plan = new Plan('Why join', 'x', [
            $this->step('goto', ['url' => '/']),
            $this->step('goto', ['url' => '/register']),
            $this->step('sign_in', ['url' => '/dashboard', 'value' => 'member']),
            $this->step('goto', ['url' => '/growth']),
        ]);

        $this->assertSame([], $plan->problems());
        // Pages before it are checked as the video's user; the rest are rehearsed signed in.
        $this->assertSame(['/', '/register'], $plan->pathsBeforeSignIn());
        $this->assertSame(['/', '/register', '/growth'], $plan->paths());
        $this->assertSame(['Step 1 (sign_in) needs url.'], (new Plan('x', 'y', [$this->step('sign_in', ['value' => 'member'])]))->problems());
        $this->assertContains('sign_in', Plan::schema()['properties']['steps']['items']['properties']['action']['enum']);
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

        // Fitting to a length at 2x leaves more room for captions and cards.
        $this->assertGreaterThan($plan->fitTo(12)->steps[0]['ms'], $plan->fitTo(12, 2)->steps[0]['ms']);
    }

    /**
     * Anthropic refuses a request whose strict tool schemas have more than 16
     * fields with type arrays or anyOf ("Schemas contains too many parameters
     * with union types"). Every tool the planner sends counts.
     */
    public function test_tool_schemas_stay_within_the_union_type_limit(): void
    {
        $unions = function (array $schema, string $path) use (&$unions): array {
            $found = isset($schema['anyOf']) || is_array($schema['type'] ?? null) ? [$path] : [];
            foreach ($schema['properties'] ?? [] as $name => $property) {
                $found = [...$found, ...$unions($property, $path.'.'.$name)];
            }
            if (isset($schema['items'])) {
                $found = [...$found, ...$unions($schema['items'], $path.'[]')];
            }
            foreach ($schema['anyOf'] ?? [] as $option) {
                $found = [...$found, ...$unions($option, $path)];
            }

            return $found;
        };

        $tools = [...(new CodeTools)->definitions(), ['name' => 'submit_plan', 'schema' => Plan::schema()]];
        $found = array_merge(...array_map(fn ($t) => $unions($t['schema'], $t['name']), $tools));

        $this->assertLessThanOrEqual(16, count($found), 'Union-typed fields: '.implode(', ', $found));
    }

    public function test_the_route_list_skips_closure_middleware(): void
    {
        // Like a controller that adds middleware in its constructor with $this->middleware(function ...).
        $controller = new class extends Controller
        {
            public function __construct()
            {
                $this->middleware(fn ($request, $next) => $next($request));
            }

            public function show(): string
            {
                return 'ok';
            }
        };
        $this->app->instance($controller::class, $controller);
        Route::get('/talent', [$controller::class, 'show'])->middleware('auth');

        $this->assertMatchesRegularExpression('#^GET /talent .*  middleware=auth$#m', (new CodeTools)->routeList());
    }

    public function test_empty_values_from_the_model_mean_not_set(): void
    {
        $plan = Plan::fromArray(['title' => 'x', 'summary' => 'y', 'steps' => [
            ['action' => 'goto', 'why' => '', 'url' => '/jobs', 'target' => array_fill_keys(Plan::TARGET_FIELDS, ''),
                'value' => '', 'title' => '', 'text' => '', 'ms' => 0, 'chapter' => '', 'only_on' => []],
            ['action' => 'type', 'why' => '', 'url' => '', 'target' => [...array_fill_keys(Plan::TARGET_FIELDS, ''), 'label' => 'Card number', 'frame' => 'iframe'],
                'value' => '4242', 'title' => '', 'text' => '', 'ms' => 400, 'chapter' => 'Pay', 'only_on' => ['mobile']],
        ]]);

        [$goto, $type] = $plan->steps;
        $this->assertNull($goto['target']);
        $this->assertSame([null, null, null, null], [$goto['value'], $goto['ms'], $goto['chapter'], $goto['only_on']]);
        $this->assertSame(['Card number', 'iframe', null], [$type['target']['label'], $type['target']['frame'], $type['target']['role']]);
        $this->assertSame([400, 'Pay', ['mobile']], [$type['ms'], $type['chapter'], $type['only_on']]);
        $this->assertSame([], (new Planner(new Drivers, new CodeTools))->problems($plan));
    }
}
