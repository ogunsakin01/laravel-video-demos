<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Ai\PlanningFailed;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\Tests\Concerns\FakesAnthropic;

/**
 * Runs the planner with the real Anthropic SDK against a local fake of the
 * Messages API, so the requests it sends and the replies it handles are real
 * without spending anything.
 */
class PlannerTest extends TestCase
{
    use FakesAnthropic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startFakeAnthropic();

        $root = sys_get_temp_dir().'/video-demo-app-'.uniqid();
        File::ensureDirectoryExists("$root/resources/views/jobs");
        File::put("$root/resources/views/jobs/create.blade.php", "<label for=\"t\">Job title</label>\n<button>Publish job</button>\n");
        $this->app->instance(CodeTools::class, new CodeTools($root));
    }

    protected function tearDown(): void
    {
        $this->stopFakeAnthropic();
        parent::tearDown();
    }

    protected function step(string $action, array $fields = []): array
    {
        $target = isset($fields['target'])
            ? [...['role' => null, 'name' => null, 'label' => null, 'placeholder' => null, 'text' => null, 'selector' => null], ...$fields['target']]
            : null;

        return [...['action' => $action, 'why' => 'x', 'url' => null, 'value' => null, 'title' => null, 'text' => null, 'ms' => null, 'chapter' => null], ...$fields, 'target' => $target];
    }

    protected function submit(string $id, array $steps): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => 'submit_plan', 'input' => ['title' => 'Post a job', 'summary' => 'Posting a job.', 'steps' => $steps]];
    }

    protected function video(): Video
    {
        return Video::create([
            'prompt' => 'How a recruiter posts a job', 'target_seconds' => 30, 'model' => 'claude-opus-5-5',
            'effort' => 'high', 'status' => VideoStatus::Planning,
            'style' => ['name' => 'Quick tip', 'direction' => 'As short as it can be. No title cards.'],
        ]);
    }

    public function test_reads_code_fixes_a_rejected_plan_and_repairs_after_a_failed_recording(): void
    {
        $goodSteps = [
            $this->step('goto', ['url' => '/jobs/create']),
            $this->step('type', ['target' => ['label' => 'Job title'], 'value' => 'Laravel developer']),
            $this->step('click', ['target' => ['role' => 'button', 'name' => 'Publish job']]),
        ];

        // 1: reads a view, with a thinking block that must be sent back unchanged
        $this->respond(1, [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
            ['type' => 'tool_use', 'id' => 'tu_read', 'name' => 'read_file', 'input' => ['path' => 'resources/views/jobs/create.blade.php', 'from_line' => null]],
        ]);
        // 2: a plan with a goto missing its url, which is refused
        $this->respond(2, [$this->submit('tu_bad', [$this->step('goto')])]);
        // 3: a valid plan
        $this->respond(3, [$this->submit('tu_plan', $goodSteps)]);
        // 4: after the recording fails, a corrected plan
        $this->respond(4, [$this->submit('tu_fixed', $goodSteps)]);

        $video = $this->video();
        $planner = app(Planner::class);
        $session = $planner->plan($video);

        $this->assertCount(3, $session->plan->steps);
        $this->assertSame('tu_plan', $session->pendingSubmit['id']);

        // The request the SDK sent
        $first = $this->request(1);
        $this->assertStringStartsWith('/v1/messages', $first['path']);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $first['headers']['anthropic-beta']);
        $this->assertSame('default', $first['body']['fallbacks']);
        $this->assertSame('claude-opus-5-5', $first['body']['model']);
        $this->assertSame(['type' => 'adaptive'], $first['body']['thinking']);
        $this->assertSame('high', $first['body']['output_config']['effort']);
        $this->assertSame(['type' => 'ephemeral'], $first['body']['cache_control']);
        $this->assertSame(['list_files', 'read_file', 'search_code', 'submit_plan'], array_column($first['body']['tools'], 'name'));
        $this->assertTrue($first['body']['tools'][3]['strict']);
        $this->assertArrayHasKey('input_schema', $first['body']['tools'][3]);
        $this->assertStringContainsString('How a recruiter posts a job', $first['body']['messages'][0]['content']);
        $this->assertStringContainsString("<style name=\"Quick tip\">\nAs short as it can be. No title cards.\n</style>", $first['body']['messages'][0]['content']);

        // The file it asked for came back, and the thinking block was returned unchanged
        $second = $this->request(2)['body']['messages'];
        $this->assertSame('sig-1', $second[1]['content'][0]['signature']);
        $this->assertSame('tu_read', $second[2]['content'][0]['tool_use_id']);
        $this->assertStringContainsString('1  <label for="t">Job title</label>', $second[2]['content'][0]['content']);

        // The rejected plan was answered with the problem
        $third = $this->request(3)['body']['messages'];
        $this->assertTrue($third[4]['content'][0]['is_error']);
        $this->assertStringContainsString('Step 1 (goto) needs url.', $third[4]['content'][0]['content']);

        // The conversation is only ever appended to, so the prompt cache holds
        $this->assertSame($second, array_slice($third, 0, count($second)));

        // A failed recording goes back as the result of the plan's submit_plan call
        $failure = RecordingResult::fromArray([
            'ok' => false, 'failedStep' => 3, 'error' => 'Could not find a visible element with role "button", name "Publish job".',
            'url' => 'http://app.test/jobs/create', 'title' => 'New job', 'snapshot' => '- button "Post job"',
        ]);
        $planner->repair($session, $video, $failure);

        $repair = $this->request(4)['body']['messages'];
        $last = end($repair);
        $this->assertSame('tu_plan', $last['content'][0]['tool_use_id']);
        $this->assertTrue($last['content'][0]['is_error']);
        $this->assertStringContainsString('button "Post job"', $last['content'][0]['content']);
        $this->assertSame('tu_fixed', $session->pendingSubmit['id']);
        $this->assertSame(1, $session->repairs);

        // Each response: 10 input, 5,000 cache writes, 20,000 cache reads, 1,000 output on Opus 5.5
        $perTurn = (10 * 4 + 5_000 * 4 * 1.25 + 20_000 * 0.20 + 1_000 * 20) / 1_000_000;
        $this->assertEqualsWithDelta($perTurn * 4, $video->fresh()->cost, 0.0001);
        $this->assertSame(4 * 20_000, $video->fresh()->cache_read_tokens);
    }

    public function test_sends_plain_requests_for_models_without_fallbacks(): void
    {
        $this->respond(1, [$this->submit('tu_plan', [$this->step('goto', ['url' => '/'])])]);
        $video = $this->video();
        $video->update(['model' => 'claude-haiku-5-5']);

        app(Planner::class)->plan($video);

        $request = $this->request(1);
        $this->assertArrayNotHasKey('fallbacks', $request['body']);
        $this->assertArrayNotHasKey('anthropic-beta', $request['headers']);
    }

    public function test_stops_at_the_cost_limit_and_on_refusals(): void
    {
        config(['video-demo.limits.max_cost_per_video' => 0.01]);
        $this->respond(1, [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'list_files', 'input' => ['path' => 'resources/views']]]);

        try {
            app(Planner::class)->plan($this->video());
            $this->fail('Expected the cost limit to stop planning.');
        } catch (PlanningFailed $e) {
            $this->assertStringContainsString('over the $0.01 limit', $e->getMessage());
        }

        config(['video-demo.limits.max_cost_per_video' => 2.0]);
        $this->respond(2, [], 'refusal', ['output_tokens' => 0]);
        $this->expectException(PlanningFailed::class);
        $this->expectExceptionMessage('The model declined this request');
        app(Planner::class)->plan($this->video());
    }
}
