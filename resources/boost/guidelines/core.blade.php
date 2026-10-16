## Laravel Video Demos (ogunsakin01/laravel-video-demos)

This app makes recorded how-to videos of itself. An AI model reads the app's code and plans a walkthrough; Playwright records it against the local app; videos are published to a storage disk and shown in a help library.

### Making videos

- Videos are made only where the studio is enabled (normally local, `APP_ENV=local` or `VIDEO_DEMO_STUDIO=true`). Never make them against production.
- Use the commands, not the studio's web page. Add `--json` to read the result.
@verbatim
<code-snippet name="Make, list and publish videos" lang="bash">
php artisan video-demo:suggest --json   # the videos worth making first, from the app's routes
php artisan video-demo:generate "How a recruiter posts a job, from the jobs page to a live listing" --length=60 --style=clean --devices=desktop,mobile --json
php artisan video-demo:generate "Show off posting a job" --style=ad --publish --json
php artisan video-demo:list --json
php artisan video-demo:publish post-a-job
php artisan video-demo:refresh   # record published videos again after UI changes, no AI cost
php artisan video-demo:refresh --changed=<commit before> --fix --json   # in a deploy pipeline: only the videos the change touched
php artisan video-demo:verify --changed=origin/main --json   # after changing the UI: which videos it broke (no recording, no AI)
php artisan video-demo:translate post-a-job fr es --json   # the same video in other languages, from its plan
php artisan video-demo:export post-a-job --format=gif --out=docs/post-a-job.gif   # gif, square or vertical
</code-snippet>
@endverbatim
- Describe the flow the way the app's users would ask about it. Styles: `clean`, `quick`, `detailed`, `showcase`, `ad` (or any added on the studio's Styles page). Versions: `desktop`, `tv`, `tablet`, `mobile`.
- `generate` waits for the video and exits non-zero if it fails; the JSON `error` says why (often a step that couldn't find an element). Pages are opened and checked before any AI is spent: broken ones (an HTTP error, a redirect to sign-in, a JavaScript error) are listed under `issues` with Laravel's error message. Fix the cause in the app (a missing demo user, seed data, a renamed button, a bug) and run it again.
- Keep costs down with `--max-cost=0.50` (the AI plans within it and stops before going over), `--max-steps=20`, shorter `--length`, and the default model (the cheapest reliable one, Claude Sonnet 5.5). `--as=admin` records the app as that type of user (types come from the app's roles, or `video-demo.recorder.user_types`), `--as=guest` signed out; the default is `VIDEO_DEMO_LOGIN_AS`.
- `--speed=1.5` sets how much faster the video plays than it was recorded (1 is real time; the default is `VIDEO_DEMO_SPEED`, 2).
- Styles: `clean`, `quick`, `detailed`, `showcase`, `ad` and `spotlight` (dark mode, zooms in on each step and highlights it), or any added on the Styles page.
- From PHP, use the `VideoDemo` facade (`Ogunsakin01\VideoDemo\Facades\VideoDemo`): `VideoDemo::make('How a manager approves leave', ['style' => 'spotlight', 'as' => 'manager', 'max_cost' => 0.5])`, then `publish`, `library`, `search`, `pages`, `checkPages`. Listen for `Ogunsakin01\VideoDemo\Events\VideoStatusChanged` to act when a video is ready.
- `php artisan video-demo:clean` frees disk space (`--browser` also removes the recorder's browser).
- Queued videos run on the package's own `video-demo` queue connection: run `php artisan queue:work video-demo`.
- After updating this package, restart queue workers (`php artisan queue:restart`, then start them again). A running worker keeps the old code, so videos would still be made by the old version. Below 1.0, move to a new minor version with `composer require ogunsakin01/laravel-video-demos:^0.x`; `composer update` stays on the current one.
- Generating spends the app owner's AI credit: the command prints an estimate first, and each video's spending limit (`--max-cost`, default `video-demo.limits.max_cost_per_video`) caps it.
- The recorder signs in as `VIDEO_DEMO_LOGIN_AS`. Sample data and test payment details (such as Stripe's `4242 4242 4242 4242`) belong in `video-demo.ai.notes` in `config/video-demo.php`.

- After changing views, routes or labels, run `video-demo:verify --changed=origin/main --json`: it lists published videos whose steps no longer work (`step`, `device`, `error`). Fix the app if it broke, or run it with `--fix` to plan the video again. A small fix, such as a renamed button, can be made with `VideoDemo::updatePlan()` and recorded with `VideoDemo::rerecord()` at no AI cost.

### Showing videos

@verbatim
<code-snippet name="Embed a published video" lang="blade">
<x-video-demo::player slug="post-a-job" />            {{-- the version that fits the screen --}}
<x-video-demo::player slug="post-a-job" device="mobile" />
<x-video-demo::search placeholder="How do I…" />
<x-video-demo::help />   {{-- in a layout: the videos that show the current page --}}
</code-snippet>
@endverbatim
- The library is at `/help/videos` (public) and `/admin/videos` (private videos too). Prefixes and middleware are in `config/video-demo.php` under `library`.
- In production, videos come from the manifest on the `video-demo.storage.disk` disk, so use a disk shared with the machine that makes them (S3, R2).

### Configuration

- Everything is in `config/video-demo.php`. Models and their providers are in `ai.models`: `anthropic` uses the Anthropic SDK (`ANTHROPIC_API_KEY`); other providers (`openai`, `gemini`, `ollama`…) need `prism-php/prism` and their key in `.env` (`OPENAI_API_KEY`, `GEMINI_API_KEY`). `VIDEO_DEMO_MODEL` sets the default model; `VIDEO_DEMO_NAME` the name on the studio and library pages.
- `APP_URL` must be the address the app is opened at: the recorder uses it.
- Outside local, the studio needs a `viewVideoDemoStudio` gate: `Gate::define('viewVideoDemoStudio', fn ($user) => $user->isAdmin());`
