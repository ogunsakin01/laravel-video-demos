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
use Ogunsakin01\VideoDemo\Recording\RecordingResult;
use Ogunsakin01\VideoDemo\SampleData\Sandbox;
use RuntimeException;

/**
 * Asks the model to read the app's code and write a step-by-step plan for the video,
 * then to fix the plan when a step fails in the browser. Works with any driver.
 */
class Planner
{
    /**
     * How much more each request costs than the last, as the conversation grows.
     */
    protected const GROWTH = 1.2;

    /**
     * Create a new planner instance.
     */
    public function __construct(protected Drivers $drivers, protected CodeTools $code) {}

    /**
     * Start a planning conversation for the video and run it until the model submits a plan.
     *
     * With a sandbox, the video is recorded on sample data: the model also
     * gets the access rules and tools to add the data the flow needs, and
     * $problems lists pages that already fail for the signed-in user.
     *
     * @param  list<array{path: string, problem: string, status: ?int, url: ?string, detail: ?string}>  $problems
     */
    public function plan(Video $video, ?Sandbox $sandbox = null, array $problems = []): PlanningSession
    {
        $session = new PlanningSession;
        $session->sampleData = $sandbox ? new SampleDataTools($sandbox) : null;
        // A new plan is made from the code it reads now.
        $this->code->takeReadFiles();
        $video->update(['source_files' => null]);
        $this->drivers->for($video->model)->addUserText($session->messages, $this->brief($video, $sandbox, $problems));

        $this->run($session, $video);

        return $session;
    }

    /**
     * Send a recording failure back as the result of submit_plan and wait for a corrected plan.
     */
    public function repair(PlanningSession $session, Video $video, RecordingResult $failure): PlanningSession
    {
        $text = $failure->describeForRepair();
        if ($session->sampleData && $failure->appError) {
            $text .= "\n\nThe app itself returned an error. On sample data that usually means a record the page expects is missing, such as a related row or a setting. Find what the page reads, check it with query_data, add it with seed_data, then submit the plan again.";
        }

        return $this->repairWith($session, $video, $text);
    }

    /**
     * Send pages that failed the check before recording back to the model,
     * so it can add the data they need or change the plan.
     *
     * @param  list<array{path: string, problem: string, status: ?int, url: ?string, detail: ?string}>  $problems
     */
    public function repairPages(PlanningSession $session, Video $video, array $problems): PlanningSession
    {
        return $this->repairWith($session, $video, "Before recording, these pages were opened as the signed-in user and failed:\n"
            .$this->listProblems($problems)
            ."\n\nA 403, a redirect or an error here usually means the user lacks a role or permission, or a record the page needs is missing. Read the middleware, policy or controller involved, add what is missing with seed_data, or change the plan. Then submit the plan again.");
    }

    /**
     * Send a failure back as the result of submit_plan and wait for a corrected plan.
     */
    protected function repairWith(PlanningSession $session, Video $video, string $failure): PlanningSession
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
                // The page may show secrets too, such as an API settings screen.
                (new Redactor)->redact($failure),
                isError: true,
            ),
        ]);
        $session->pendingSubmit = null;
        $session->pendingResults = [];

        $this->run($session, $video);

        return $session;
    }

    /**
     * Run the conversation until the model submits a valid plan or a limit is reached.
     */
    protected function run(PlanningSession $session, Video $video): void
    {
        $maxTurns = (int) config('video-demo.limits.max_turns', 30);
        $maxCost = $video->spendLimit();
        $driver = $this->drivers->for($video->model);
        $tools = [...$this->code->definitions(), ...($session->sampleData?->definitions() ?? []), $this->submitTool()];

        while ($session->turns < $maxTurns) {
            if ($video->stopRequested()) {
                throw new VideoStopped('Stopped by you.');
            }
            // Each request costs a little more than the last as the conversation grows,
            // so stop before one that would take the video over its limit.
            if ($video->cost >= $maxCost) {
                throw new PlanningFailed(sprintf('Stopped at $%.2f: this video has reached its $%.2f limit. Raise the limit to plan it again.', $video->cost, $maxCost));
            }
            $nextCost = $session->lastCost * self::GROWTH;
            if ($session->lastCost > 0 && $video->cost + $nextCost > $maxCost) {
                throw new PlanningFailed(sprintf('Stopped at $%.2f: the next request would take this video past its $%.2f limit. Raise the limit, or ask for a shorter video.', $video->cost, $maxCost));
            }

            $session->turns++;
            $turn = $driver->send($session->messages, $this->systemPrompt(), $tools, $video->model, $video->effort, $this->longCache($video));
            $video->addUsage($turn->usage);
            $session->lastCost = $turn->usage->cost;

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

                if ($session->sampleData?->handles($call['name'])) {
                    $results[] = $this->runSampleDataTool($session->sampleData, $call, $video);
                    $video->addLog($session->sampleData->describe($call['name'], $call['input']));

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
                $this->keepSourceFiles($video);

                return;
            }

            $driver->addToolOutputs($session->messages, $this->withBudget($results, $video, $session->lastCost, $maxCost));
        }

        throw new PlanningFailed("No plan after $maxTurns requests to the model. Try a narrower description, or raise video-demo.limits.max_turns.");
    }

    /**
     * Note the files the model read on the video, with those it read before,
     * so video-demo:verify --changed knows which videos a change may break.
     */
    protected function keepSourceFiles(Video $video): void
    {
        $files = array_values(array_unique([...($video->source_files ?? []), ...$this->code->takeReadFiles()]));
        sort($files);
        $video->update(['source_files' => $files]);
    }

    /**
     * Add a note to the turn's last result saying how many more requests the budget allows.
     *
     * The note is part of a new message, never edited into an earlier one, so the cached conversation stays valid.
     *
     * @param  list<ToolOutput>  $results
     * @return list<ToolOutput>
     */
    protected function withBudget(array $results, Video $video, float $lastCost, float $maxCost): array
    {
        if ($results === [] || $lastCost <= 0) {
            return $results;
        }

        $left = max(0, (int) floor(($maxCost - $video->cost) / ($lastCost * self::GROWTH)));
        $note = $left <= 2
            ? "\n\n[Budget: about $left more request(s) before this video's spending limit. Submit the plan now with what you know.]"
            : "\n\n[Budget: about $left more requests before this video's spending limit.]";
        $last = array_pop($results);

        return [...$results, new ToolOutput($last->id, $last->name, $last->input, $last->content.$note, $last->isError)];
    }

    /**
     * Determine if the conversation should use the 1-hour cache instead of the 5-minute one.
     *
     * Recording several versions can outlast 5 minutes, and a repair afterwards would pay to cache the whole conversation again.
     */
    protected function longCache(Video $video): bool
    {
        return count($video->deviceList()) * $video->target_seconds * $video->playbackSpeed() > 240;
    }

    /**
     * Run one code tool call, turning a refused input into an error result.
     *
     * @param  array{id: string, name: string, input: array}  $call
     */
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

    /**
     * Run one sample data tool call, turning a failure into an error result.
     *
     * @param  array{id: string, name: string, input: array}  $call
     */
    protected function runSampleDataTool(SampleDataTools $tools, array $call, Video $video): ToolOutput
    {
        try {
            return new ToolOutput($call['id'], $call['name'], $call['input'], $tools->run($call['name'], $call['input'], $video));
        } catch (RuntimeException|InvalidArgumentException $e) {
            return new ToolOutput($call['id'], $call['name'], $call['input'], $e->getMessage(), isError: true);
        }
    }

    /**
     * List page problems, one per line.
     *
     * @param  list<array{path: string, problem: string, status: ?int, url: ?string, detail: ?string}>  $problems
     */
    protected function listProblems(array $problems): string
    {
        return implode("\n", array_map(fn ($p) => "- {$p['path']}: {$p['problem']}"
            .(! empty($p['url']) && parse_url($p['url'], PHP_URL_PATH) !== $p['path'] ? ' (ended on '.$p['url'].')' : '')
            .(! empty($p['detail']) ? ": {$p['detail']}" : ''), $problems));
    }

    /**
     * Get a short log line describing a tool call.
     */
    protected function describeToolCall(string $name, array $input): string
    {
        return match ($name) {
            'read_file' => 'Read '.($input['path'] ?? ''),
            'list_files' => 'Listed '.($input['path'] ?? ''),
            'search_code' => 'Searched for "'.($input['pattern'] ?? '').'"',
            default => $name,
        };
    }

    /**
     * Find the problems that keep a plan from being recorded.
     *
     * @return list<string>
     */
    public function problems(Plan $plan, ?int $maxSteps = null): array
    {
        return $plan->problems($maxSteps);
    }

    /**
     * Get the definition of the submit_plan tool.
     */
    protected function submitTool(): array
    {
        return [
            'name' => 'submit_plan',
            'description' => 'Submit the finished video plan. The recorder plays it in a real browser; if a step fails you get the error and the page as it was, and can submit a corrected plan.',
            'schema' => Plan::schema(),
        ];
    }

    /**
     * Build the first message: the request, its limits, the screens and the app's routes.
     */
    protected function brief(Video $video, ?Sandbox $sandbox = null, array $problems = []): string
    {
        $notes = trim((string) config('video-demo.ai.notes'));
        $devices = $video->deviceList();
        $user = $sandbox ? $this->describeSampleUser($video, $sandbox) : RecordAs::describe($video);
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
            $this->editedPlan($video),
            VoiceLines::language($video),
            $video->hasVoiceOver()
                ? "Voice-over: a narrator speaks each step's say line aloud while the viewer watches it happen, in the style's tone.\n".VoiceLines::GUIDE."\nThe recorder keeps a step going until its line is finished, so lines never overlap, but every line adds to the length: plan fewer steps or shorter captions to stay near the target."
                : 'This video has no voice-over: leave every step\'s say empty.',
            $sandbox ? $this->sampleDataBrief($sandbox, $problems) : null,
            $sandbox && ($rules = (new AccessRules)->describe()) !== '' ? "<access_rules>\n$rules\n</access_rules>" : null,
            "<routes>\n".$this->code->routeList(allMiddleware: $sandbox !== null)."\n</routes>",
        ]));
    }

    /**
     * Describe the plan the developer edited by hand, for a new plan to start
     * from, or null when it wasn't edited.
     */
    protected function editedPlan(Video $video): ?string
    {
        if (! $video->plan_edited_at || empty($video->plan['steps'])) {
            return null;
        }

        $steps = array_map(function ($step) {
            $kept = array_filter(array_diff_key($step, array_flip(['voice_ms', 'hold_ms', 'why'])), fn ($v) => $v !== null && $v !== '' && $v !== []);
            if (isset($kept['target'])) {
                $kept['target'] = array_filter($kept['target'], fn ($v) => $v !== null && $v !== '');
            }

            return json_encode($kept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $video->plan['steps']);

        return (new Redactor)->redact("<edited_plan>\n".implode("\n", $steps)."\n</edited_plan>")
            ."\nThe developer edited the video's last plan by hand. Start from it: keep their wording, the steps they added or removed and the targets they set, and change only what the request or the app now needs.";
    }

    /**
     * Describe the sample database and what to do with it.
     *
     * @param  list<array{path: string, problem: string, status: ?int, url: ?string, detail: ?string}>  $problems
     */
    protected function sampleDataBrief(Sandbox $sandbox, array $problems): string
    {
        $info = $sandbox->info;
        $tables = implode(', ', array_map(fn ($t, $n) => "$t $n", array_keys($info['tables']), $info['tables']));

        return implode("\n\n", array_filter([
            '<sample_data>',
            'The recorder runs against a temporary copy of the app\'s database, made for this video, not the developer\'s own data. The app\'s migrations ran on it'
                .($info['seeded'] ? ', then these seeders: '.implode(', ', $info['seeded']) : ', and no seeders ran, so it holds only what you add').'.',
            $info['warnings'] ? "Seeder problems:\n- ".implode("\n- ", $info['warnings']) : null,
            $tables !== '' ? "Tables with rows: $tables. Every other table is empty." : 'Every table is empty.',
            $problems ? "Checked just now as the signed-in user, these pages failed:\n".$this->listProblems($problems) : null,
            "Before you submit the plan, make sure the data the flow needs exists:\n"
                ."1. For each page in the flow, read what decides access: the route's middleware (listed with each route, with the files under access_rules), and the policies, gates and checks its controller and views use. Work out what the signed-in user needs to pass them, such as a role, a permission, a verified email, a finished onboarding step or an active subscription, and which records must exist and belong to them.\n"
                ."2. Read what the pages show, so they look lived in: lists with several entries (three to six), details and dashboards with real-looking content, and records by other users where the page shows other people.\n"
                ."3. Check what is there with query_data, then add what is missing with seed_data. Values appear on screen, so make them realistic.\n"
                .'4. Use the ids seed_data returns in the plan\'s paths, such as /tickets/3.',
            'Only this copy changes, and it is deleted after recording. If a page still fails before recording, you get the reason and can add data or change the plan.',
            '</sample_data>',
        ]));
    }

    /**
     * Describe the user signed in to the sample database, or null for a guest.
     */
    protected function describeSampleUser(Video $video, Sandbox $sandbox): ?string
    {
        $user = $sandbox->info['user'];
        if (! $user) {
            return null;
        }

        $type = str_starts_with((string) $video->record_as, RecordAs::TYPE) ? 'a '.RecordAs::label($video->record_as).' user, ' : '';

        return $type.'user #'.$user['id'].($user['email'] ?? null ? " ({$user['email']})" : '')
            .(! empty($user['roles']) ? ', roles: '.implode(', ', $user['roles']) : '')
            .(! empty($user['permissions']) ? ', permissions: '.implode(', ', $user['permissions']) : '')
            .(isset($user['email_verified']) ? ($user['email_verified'] ? ', email verified' : ', email not verified') : '');
    }

    /**
     * Get the system prompt for planning.
     */
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
