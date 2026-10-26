<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\Ai\CodeTools;
use Ogunsakin01\VideoDemo\Ai\CostEstimator;
use Ogunsakin01\VideoDemo\Ai\Planner;
use Ogunsakin01\VideoDemo\Enums\VideoStatus;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

/**
 * Tests planning with models from other providers through Prism, the same way
 * the Anthropic driver plans.
 */
class PrismDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['video-demo.ai.models.test-gpt' => ['label' => 'Test GPT', 'provider' => 'openai', 'input' => 1.00, 'output' => 4.00]]);
        config(['video-demo.ai.models.local-llm' => ['label' => 'Local', 'provider' => 'ollama']]);

        $root = sys_get_temp_dir().'/video-demo-prism-'.uniqid();
        File::ensureDirectoryExists("$root/resources/views/jobs");
        File::put("$root/resources/views/jobs/create.blade.php", "<label for=\"t\">Job title</label>\n<button>Publish job</button>\n");
        $this->app->instance(CodeTools::class, new CodeTools($root));
    }

    protected function toolCall(string $id, string $name, array $arguments): ToolCall
    {
        return new ToolCall($id, $name, $arguments);
    }

    protected function reply(array $calls, int $prompt = 1_000, int $completion = 200): TextResponseFake
    {
        return TextResponseFake::make()
            ->withToolCalls($calls)
            ->withFinishReason($calls ? FinishReason::ToolCalls : FinishReason::Stop)
            ->withUsage(new Usage($prompt, $completion));
    }

    protected function steps(): array
    {
        $step = fn (string $action, array $fields = []) => [
            'action' => $action, 'why' => 'x', 'url' => null, 'target' => null, 'value' => null,
            'title' => null, 'text' => null, 'ms' => null, 'chapter' => null, 'only_on' => null, ...$fields,
        ];

        return [
            $step('goto', ['url' => '/jobs/create']),
            $step('click', ['target' => ['role' => 'button', 'name' => 'Publish job', 'label' => null, 'placeholder' => null, 'text' => null, 'selector' => null, 'frame' => null]]),
        ];
    }

    public function test_plans_reads_code_and_repairs_with_another_provider(): void
    {
        $plan = ['title' => 'Post a job', 'summary' => 'Posting a job.', 'steps' => $this->steps()];
        $fake = Prism::fake([
            $this->reply([$this->toolCall('c1', 'read_file', ['path' => 'resources/views/jobs/create.blade.php', 'from_line' => null])]),
            $this->reply([$this->toolCall('c2', 'submit_plan', $plan)]),
            $this->reply([$this->toolCall('c3', 'submit_plan', $plan)]),
        ]);

        $video = Video::create([
            'prompt' => 'How a recruiter posts a job', 'target_seconds' => 30, 'model' => 'test-gpt',
            'effort' => 'high', 'status' => VideoStatus::Planning,
        ]);
        $planner = app(Planner::class);
        $session = $planner->plan($video);

        $this->assertCount(2, $session->plan->steps);
        $this->assertSame('c2', $session->pendingSubmit['id']);

        $planner->repair($session, $video, RecordingResult::fromArray([
            'ok' => false, 'failedStep' => 2, 'error' => 'Could not find a visible element with role "button", name "Publish job".',
            'url' => 'http://app.test/jobs/create', 'snapshot' => '- button "Post job"',
        ]));
        $this->assertSame('c3', $session->pendingSubmit['id']);

        $fake->assertRequest(function (array $requests) {
            $this->assertCount(3, $requests);
            /** @var TextRequest $first */
            $first = $requests[0];
            $this->assertSame('openai', $first->provider());
            $this->assertSame('test-gpt', $first->model());
            $this->assertSame(1, $first->maxSteps());
            $this->assertSame(['list_files', 'read_file', 'search_code', 'look_at_page', 'submit_plan', 'cannot_make_video'], array_map(fn ($t) => $t->name(), $first->tools()));
            $this->assertInstanceOf(UserMessage::class, $first->messages()[0]);

            // The file the model asked for came back as a tool result.
            $second = $requests[1]->messages();
            $this->assertInstanceOf(AssistantMessage::class, $second[1]);
            $this->assertInstanceOf(ToolResultMessage::class, $second[2]);
            $this->assertStringContainsString('1  <label for="t">Job title</label>', $second[2]->toolResults[0]->result);

            // The failed recording went back as the result of submit_plan.
            $repair = $requests[2]->messages();
            $last = end($repair);
            $this->assertSame('c2', $last->toolResults[0]->toolCallId);
            $this->assertStringStartsWith('Error: Step 2 failed', $last->toolResults[0]->result);
            $this->assertStringContainsString('button "Post job"', $last->toolResults[0]->result);
        });

        // 3 replies of 1,000 prompt and 200 completion tokens at $1 and $4 per million.
        $this->assertEqualsWithDelta(3 * (1_000 * 1.00 + 200 * 4.00) / 1_000_000, $video->fresh()->cost, 0.000001);
    }

    public function test_complete_with_ai_works_with_another_provider(): void
    {
        $fake = Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['title' => 'Approve a holiday', 'prompt' => 'How a manager approves a holiday request.', 'start_path' => '/inbox', 'target_seconds' => 45])
                ->withUsage(new Usage(2_000, 300)),
        ]);

        $this->postJson('/video-demo/studio/complete', ['prompt' => 'approve holiday', 'model' => 'test-gpt'])
            ->assertOk()
            ->assertJson(['title' => 'Approve a holiday', 'start_path' => '/inbox', 'target_seconds' => 45, 'cost_known' => true]);

        $fake->assertRequest(fn (array $requests) => $this->assertSame('openai', $requests[0]->provider()));
    }

    public function test_models_without_prices_work_but_show_no_estimate(): void
    {
        $this->assertFalse((new CostEstimator)->estimate('local-llm', 'high', 60, 80)['known']);
        $this->postJson('/video-demo/studio/estimate', ['model' => 'local-llm', 'effort' => 'high', 'target_seconds' => 60])
            ->assertOk()
            ->assertJson(['known' => false, 'low' => null]);

        Prism::fake([StructuredResponseFake::make()->withStructured(['title' => 'T', 'prompt' => 'P.', 'start_path' => null, 'target_seconds' => 30])]);
        $this->postJson('/video-demo/studio/complete', ['prompt' => '', 'model' => 'local-llm'])
            ->assertOk()
            ->assertJson(['cost' => 0, 'cost_known' => false]);
    }
}
