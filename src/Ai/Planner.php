<?php

namespace Ogunsakin01\VideoDemo\Ai;

use InvalidArgumentException;
use Ogunsakin01\VideoDemo\Ai\Drivers\Drivers;
use Ogunsakin01\VideoDemo\Ai\Drivers\ToolOutput;
use Ogunsakin01\VideoDemo\Ai\Drivers\Turn;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Devices;
use Ogunsakin01\VideoDemo\Recording\Plan;
use Ogunsakin01\VideoDemo\Recording\RecordAs;
use Ogunsakin01\VideoDemo\Recording\Recorder;
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use RuntimeException;

/**
 * Asks the model to read the app's code and write a step-by-step plan for
 * the video, then to fix the plan when a step fails in the browser. Works
 * with any driver: Claude through the Anthropic SDK, or other providers
 * through Prism.
 */
class Planner
{
    public function __construct(protected Drivers $drivers, protected CodeTools $code) {}

    public function plan(Video $video): PlanningSession
    {
        $session = new PlanningSession;
        $this->drivers->for($video->model)->addUserText($session->messages, $this->brief($video));

        $this->run($session, $video);

        return $session;
    }

    /** Sends the failure back as the result of submit_plan and waits for a corrected plan */
    public function repair(PlanningSession $session, Video $video, RecordingResult $failure): PlanningSession
    {
        if ($session->pendingSubmit === null) {
            throw new PlanningFailed('There is no plan to repair.');
        }

        $session->repairs++;
        $this->drivers->for($video->model)->addToolOutputs($session->messages, [
            ...$session->pendingResults,
            new ToolOutput(
                $session->pendingSubmit['id'],
                'submit_plan',
                $session->pendingSubmit['input'],
                // The page may show secrets too, such as an API settings screen
                (new Redactor)->redact($failure->describeForRepair()),
                isError: true,
            ),
        ]);
        $session->pendingSubmit = null;
        $session->pendingResults = [];

        $this->run($session, $video);

        return $session;
    }

    protected function run(PlanningSession $session, Video $video): void
    {
        $maxTurns = (int) config('video-demo.limits.max_turns', 30);
        $maxCost = $video->spendLimit();
        $driver = $this->drivers->for($video->model);
        $tools = [...$this->code->definitions(), $this->submitTool()];
        $lastCost = 0.0;

        while ($session->turns < $maxTurns) {
            if ($video->stopRequested()) {
                throw new VideoStopped('Stopped by you.');
            }
            // Each request costs a little more than the last as the conversation
            // grows, so stop before one that would take the video over its limit
            $nextCost = $lastCost * 1.2;
            if ($lastCost > 0 && $video->cost + $nextCost > $maxCost) {
                throw new PlanningFailed(sprintf('Stopped at $%.2f: the next request would take this video past its $%.2f limit. Raise the limit, or ask for a shorter video.', $video->cost, $maxCost));
            }

            $session->turns++;
            $turn = $driver->send($session->messages, $this->systemPrompt(), $tools, $video->model, $video->effort, $this->longCache($video));
            $video->addUsage($turn->usage);
            $lastCost = $turn->usage->cost;

            if ($turn->stop === Turn::REFUSAL) {
                throw new PlanningFailed('The model declined this request'.($turn->refusal ? ': '.$turn->refusal : '.'));
            }
            if ($turn->stop === Turn::MAX_TOKENS) {
                throw new PlanningFailed('The plan was longer than one response allows. Ask for a shorter video or split the flow.');
            }
            if ($video->cost > $maxCost) {
                throw new PlanningFailed(sprintf('Stopped at $%.2f, over this video\'s $%.2f limit.', $video->cost, $maxCost));
            }

            if ($turn->stop !== Turn::TOOL_USE) {
                $driver->addUserText($session->messages, 'Call submit_plan with the finished plan now.');

                continue;
            }

            $results = [];
            $submitted = null;
            foreach ($turn->toolCalls as $call) {
                if ($call['name'] === 'submit_plan') {
                    $plan = Plan::fromArray($call['input']);
                    $problems = $this->problems($plan, $video->max_steps);
                    if ($problems === [] && $submitted === null) {
                        $submitted = [$call, $plan];
                    } else {
                        $results[] = new ToolOutput($call['id'], $call['name'], $call['input'], $problems ? "The plan was not accepted:\n- ".implode("\n- ", $problems) : 'Submit one plan at a time.', isError: true);
                    }

                    continue;
                }

                $results[] = $this->runCodeTool($call);
                $hidden = $this->code->hiddenSecrets();
                $video->addLog($this->describeToolCall($call['name'], $call['input'])
                    .($hidden ? " ($hidden ".($hidden === 1 ? 'secret' : 'secrets').' hidden)' : ''));
            }

            if ($submitted) {
                [$session->pendingSubmit, $session->plan] = $submitted;
                $session->pendingResults = $results;

                return;
            }

            $driver->addToolOutputs($session->messages, $this->withBudget($results, $video, $lastCost, $maxCost));
        }

        throw new PlanningFailed("No plan after $maxTurns requests to the model. Try a narrower description, or raise video-demo.limits.max_turns.");
    }

    /**
     * Tells the model how many more requests the video's budget allows, on
     * the last result of the turn, so it wraps up in time. Appended, never
     * edited in, so the cached conversation stays valid.
     *
     * @param  list<ToolOutput>  $results
     * @return list<ToolOutput>
     */
    protected function withBudget(array $results, Video $video, float $lastCost, float $maxCost): array
    {
        if ($results === [] || $lastCost <= 0) {
            return $results;
        }

        $left = max(0, (int) floor(($maxCost - $video->cost) / ($lastCost * 1.2)));
        $note = $left <= 2
            ? "\n\n[Budget: about $left more request(s) before this video's spending limit. Submit the plan now with what you know.]"
            : "\n\n[Budget: about $left more requests before this video's spending limit.]";
        $last = array_pop($results);

        return [...$results, new ToolOutput($last->id, $last->name, $last->input, $last->content.$note, $last->isError)];
    }

    /**
     * Whether to keep the conversation in the 1-hour cache instead of the
     * 5-minute one: recording several versions can outlast 5 minutes, and a
     * repair afterwards would pay to cache the whole conversation again
     */
    protected function longCache(Video $video): bool
    {
        return count($video->deviceList()) * $video->target_seconds * Recorder::speed() > 240;
    }

    /** @param array{id: string, name: string, input: array} $call */
    protected function runCodeTool(array $call): ToolOutput
    {
        if (! $this->code->handles($call['name'])) {
            return new ToolOutput($call['id'], $call['name'], $call['input'], "There is no tool called {$call['name']}.", isError: true);
        }

        try {
            return new ToolOutput($call['id'], $call['name'], $call['input'], $this->code->run($call['name'], $call['input']));
        } catch (RuntimeException|InvalidArgumentException $e) {
            return new ToolOutput($call['id'], $call['name'], $call['input'], $e->getMessage(), isError: true);
        }
    }

    protected function describeToolCall(string $name, array $input): string
    {
        return match ($name) {
            'read_file' => 'Read '.$input['path'],
            'list_files' => 'Listed '.$input['path'],
            'search_code' => 'Searched for "'.$input['pattern'].'"',
            default => $name,
        };
    }

    /** @return list<string> */
    public function problems(Plan $plan, ?int $maxSteps = null): array
    {
        $problems = [];
        $limit = $maxSteps ?: 250;
        if ($plan->steps === []) {
            $problems[] = 'The plan has no steps.';
        }
        if (count($plan->steps) > $limit) {
            $problems[] = 'The plan has '.count($plan->steps)." steps; the limit is $limit. Combine or drop steps to fit.";
        }

        foreach ($plan->steps as $i => $step) {
            $n = $i + 1;
            $action = $step['action'] ?? null;
            // A frame alone isn't a target: something must identify the element in it
            $hasTarget = is_array($step['target'] ?? null)
                && array_filter(array_diff_key($step['target'], ['frame' => true]), fn ($v) => $v !== null && $v !== '') !== [];

            $missing = match ($action) {
                'goto' => empty($step['url']) ? 'url' : null,
                'click', 'hover', 'check' => $hasTarget ? null : 'target',
                'type', 'select' => ! $hasTarget ? 'target' : (($step['value'] ?? '') === '' ? 'value' : null),
                'press' => empty($step['value']) ? 'value' : null,
                'caption', 'card' => empty($step['title']) ? 'title' : null,
                'scroll', 'wait', 'pause' => null,
                default => 'a known action',
            };

            if ($missing) {
                $problems[] = "Step $n ($action) needs $missing.";
            }
            if ($action === 'goto' && ! empty($step['url']) && preg_match('~^[a-z]+://~i', $step['url'])) {
                $problems[] = "Step $n: use a path such as /jobs, not a full URL.";
            }
        }

        return $problems;
    }

    protected function submitTool(): array
    {
        return [
            'name' => 'submit_plan',
            'description' => 'Submit the finished video plan. The recorder plays it in a real browser; if a step fails you get the error and the page as it was, and can submit a corrected plan.',
            'schema' => Plan::schema(),
        ];
    }

    protected function brief(Video $video): string
    {
        $notes = trim((string) config('video-demo.ai.notes'));
        $devices = $video->deviceList();
        $user = RecordAs::describe($video);
        $signedIn = $user === null
            ? 'The recorder is signed out (a guest) when the video starts, so use only pages that don\'t need signing in.'
            : "The recorder is already signed in as $user when the video starts; plan only what that user can see and do.";

        return implode("\n\n", array_filter([
            "<video_request>\n{$video->prompt}\n</video_request>",
            "Target length: about {$video->target_seconds} seconds.",
            $video->max_steps ? "The plan may have at most {$video->max_steps} steps." : null,
            sprintf('Budget: this video may spend at most $%.2f on AI requests, including any repairs. Read only the code you need.', $video->spendLimit()),
            ! empty($video->style['direction']) ? "<style name=\"{$video->style['name']}\">\n{$video->style['direction']}\n</style>\nFollow this style for the tone, the captions and cards you use, and how much you explain." : null,
            $video->start_path ? "Start at: {$video->start_path}" : null,
            count($devices) > 1
                ? 'The same plan is recorded on each of these screens: '.implode('; ', array_map(Devices::describe(...), $devices)).". Choose targets that exist on all of them. Where a layout differs, such as navigation behind a menu button on small screens, add the extra steps with only_on naming the screens that need them. $signedIn"
                : 'Screen: '.Devices::describe($devices[0]).". $signedIn",
            $notes !== '' ? "<notes_from_the_developer>\n$notes\n</notes_from_the_developer>" : null,
            "<routes>\n".$this->code->routeList()."\n</routes>",
        ]));
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
        You plan short screen-recorded videos that show people how to use a web app. You have read access to the app's source code, and a recorder that plays your plan in a real browser against the developer's local copy of the app, which has sample data.

        Read the code to learn how the flow really works: which page it starts on, the exact visible labels of links, buttons and form fields, which fields are required, what validation will reject, and what the user sees after each step. Start from the routes, then the controllers and views involved. Read what you need to get the steps right and stop there; you do not need to understand the whole app.

        Then call submit_plan once with the plan. Each step is one thing that happens on screen:
        - goto opens a path. Use it to start and when a viewer would naturally arrive at a page, not to skip steps the video is meant to teach.
        - click, hover and check act on an element; type fills a field with value; select picks an option by its label; press sends a key such as Enter; scroll moves the page (target scrolls to an element; otherwise ms is ignored and the page scrolls by one screen); wait waits for an element to appear.
        - caption shows a short title and sentence over the bottom of the page while the app stays visible; card shows a full-screen title card. Use a card to open and close the video, and captions to explain what the viewer is looking at. Keep each caption title under 8 words and its sentence under 20.
        - pause holds still so the viewer can take in a result.

        Payments and other services often show their fields inside an iframe, such as the card fields of Stripe Elements or a PayPal button. Give the iframe's CSS selector in target.frame for those, and check the developer's notes for test card numbers and test accounts; never use real payment details. Hosted pages on another site (a Stripe Checkout page, say) are reached by clicking through from the app, not with goto. Steps that need an email, a text message or a CAPTCHA can't be recorded; if the flow depends on one, say so in a caption and skip to what the user sees next.

        Identify elements the way a user sees them. Prefer role and name together (role "button", name "Post job"), then label for form fields, then visible text. Use a CSS selector only when nothing visible identifies the element. Labels must match what the rendered page shows, after translations and components are applied, so check the view and any component it uses.

        Pace the plan to the target length: a caption reads in about 3 seconds, a card in about 4, and each click or field takes 1 to 2 seconds. The recorder adjusts captions and pauses to land close to the target, so get the content right rather than the exact timing. Type realistic sample values that will pass validation. Mark the start of each part of the flow with chapter so viewers can jump to it.

        The video will be shown to the app's users, so explain things in their terms, not in terms of the code. Do not show secrets, other people's personal details, or admin-only screens unless the request is about them. Avoid steps that delete data or send messages to real people unless the request is about exactly that.

        If a step fails while recording, you will get the error, the page address and a list of what was on the page. Fix the plan and submit it again.
        PROMPT;
    }
}
