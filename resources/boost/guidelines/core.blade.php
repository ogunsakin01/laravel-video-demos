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
</code-snippet>
@endverbatim
- Describe the flow the way the app's users would ask about it. Styles: `clean`, `quick`, `detailed`, `showcase`, `ad` (or any added on the studio's Styles page). Versions: `desktop`, `tv`, `tablet`, `mobile`.
- `generate` waits for the video and exits non-zero if it fails; the JSON `error` says why (often a step that couldn't find an element). Pages are opened and checked before any AI is spent: broken ones (an HTTP error, a redirect to sign-in, a JavaScript error) are listed under `issues` with Laravel's error message. Fix the cause in the app (a missing demo user, seed data, a renamed button, a bug) and run it again.
- Keep costs down with `--max-cost=0.50` (the AI plans within it and stops before going over), `--max-steps=20`, shorter `--length`, and the default model (the cheapest reliable one, Claude Sonnet 5.5). `--as=guest` or `--as=<user id or email>` records the app as that user; the default is `VIDEO_DEMO_LOGIN_AS`.
- Queued videos run on the package's own `video-demo` queue connection: run `php artisan queue:work video-demo`.
- After updating this package, restart queue workers (`php artisan queue:restart`, then start them again). A running worker keeps the old code, so videos would still be made by the old version. Below 1.0, move to a new minor version with `composer require ogunsakin01/laravel-video-demos:^0.x`; `composer update` stays on the current one.
- Generating spends the app owner's AI credit: the command prints an estimate first, and each video's spending limit (`--max-cost`, default `video-demo.limits.max_cost_per_video`) caps it.
- The recorder signs in as `VIDEO_DEMO_LOGIN_AS`. Sample data and test payment details (such as Stripe's `4242 4242 4242 4242`) belong in `video-demo.ai.notes` in `config/video-demo.php`.

### Showing videos

@verbatim
<code-snippet name="Embed a published video" lang="blade">
<x-video-demo::player slug="post-a-job" />            {{-- the version that fits the screen --}}
<x-video-demo::player slug="post-a-job" device="mobile" />
<x-video-demo::search placeholder="How do I…" />
</code-snippet>
@endverbatim
- The library is at `/help/videos` (public) and `/admin/videos` (private videos too). Prefixes and middleware are in `config/video-demo.php` under `library`.
- In production, videos come from the manifest on the `video-demo.storage.disk` disk, so use a disk shared with the machine that makes them (S3, R2).

### Configuration

- Everything is in `config/video-demo.php`. Models and their providers are in `ai.models`: `anthropic` uses the Anthropic SDK (`ANTHROPIC_API_KEY`); other providers (`openai`, `gemini`, `ollama`…) need `prism-php/prism` and their key in `.env` (`OPENAI_API_KEY`, `GEMINI_API_KEY`). `VIDEO_DEMO_MODEL` sets the default model; `VIDEO_DEMO_NAME` the name on the studio and library pages.
- `APP_URL` must be the address the app is opened at: the recorder uses it.
- Outside local, the studio needs a `viewVideoDemoStudio` gate: `Gate::define('viewVideoDemoStudio', fn ($user) => $user->isAdmin());`
