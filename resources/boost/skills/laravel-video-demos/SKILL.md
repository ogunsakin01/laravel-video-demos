---
name: laravel-video-demos
description: Make, fix, publish and embed the app's recorded how-to videos with ogunsakin01/laravel-video-demos. Use when asked to create a how-to, help-center, onboarding or product demo video of the app, embed or list videos in pages, keep videos working after UI changes, translate or export videos, or set up where published videos are stored.
---

# Laravel Video Demos

The app records how-to videos of itself. An AI model reads the app's code and plans the steps; Playwright records them in a real browser against the local app; published videos are written to a storage disk and shown in a help library and in pages.

## When to use this skill

- The user asks for a video, walkthrough, tutorial, product tour or demo of a flow in the app.
- A page should show its videos, or a help/video library is needed.
- Views, routes or labels changed and published videos may no longer match.
- Videos should reach production, run on deploy, come in other languages, or be exported as GIFs.

## Rules

- Videos are made only where the studio is enabled: locally (`APP_ENV=local`) or with `VIDEO_DEMO_STUDIO=true`. Never point the recorder at production.
- Making a video spends the app owner's AI credit. Each video has a spending limit (`--max-cost`, $0.50 by default) that the AI plans within. Recording an existing plan again (`refresh`, `rerecord`, `--speed`, other devices) costs nothing. Say what a command will cost before running one that plans.
- Prefer the Artisan commands with `--json` over the studio's web pages: they wait, print the result and exit non-zero on failure.
- Queued videos run on the package's own `video-demo` queue connection. Start workers with `php artisan video-demo:work` (or `queue:work video-demo`). After updating the package, run `php artisan video-demo:install` and restart workers.

## Making a video

```bash
php artisan video-demo:doctor --json    # first, or when something fails: checks the setup and says how to fix it
php artisan video-demo:suggest --json   # the videos worth making first, from the app's routes
php artisan video-demo:generate "How a recruiter posts a job, from the jobs page to a live listing" \
  --length=60 --style=clean --devices=desktop,mobile --as=recruiter --max-cost=0.50 --json
php artisan video-demo:list --json
php artisan video-demo:publish post-a-job            # --unpublish takes it out of the library
```

- Describe the flow the way the app's users would ask about it, with the start and the end state.
- Styles: `clean`, `quick`, `detailed`, `showcase`, `ad`, `spotlight`, `promo`, or any added on the studio's Styles page. Versions: `desktop`, `tv`, `tablet`, `mobile`.
- `--as` records as a type of user (from the app's roles, or `video-demo.recorder.user_types`), `guest` for signed out, or a user id or email. The default is `VIDEO_DEMO_LOGIN_AS`.
- Videos are recorded on a temporary sample database by default (`--sample-data`), a copy built from the app's migrations and seeders where the AI adds the records a page needs. The app's own data isn't touched.
- The AI reads the code, and looks at a page when the code doesn't settle a label (up to 3 times, `VIDEO_DEMO_PAGE_LOOKS`). On sample data the plan is then rehearsed without recording, so a step that doesn't work is fixed before anything is recorded (`VIDEO_DEMO_REHEARSE`).
- Pages are opened and checked before any AI is spent. Broken ones (an HTTP error, a redirect to sign-in, a JavaScript error, or for a separate frontend a request to the API that fails) come back under `issues` with Laravel's error. Fix the cause in the app (seed data, a missing user, a bug) and run it again.
- When `generate` fails, the JSON `error` names the step, usually an element it couldn't find. Fix the app or the plan, then run it again.

From PHP, use the facade:

```php
use Ogunsakin01\VideoDemo\Facades\VideoDemo;

$video = VideoDemo::make('How a manager approves leave', [
    'style' => 'spotlight', 'as' => 'manager', 'length' => 45,
    'devices' => ['desktop', 'mobile'], 'max_cost' => 0.5,
]);                                   // queued; 'queue' => false makes it now
VideoDemo::publish($video);           // once it is ready
VideoDemo::find('approve-leave');     // by id or slug
VideoDemo::estimate(seconds: 45);     // ['low' => …, 'expected' => …, 'high' => …] in dollars
```

Listen for `Ogunsakin01\VideoDemo\Events\VideoStatusChanged` (`$video`, `$from`, `$to`) to act when a video is ready or fails, and `VideoBroke` when a check finds a published video no longer works.

## Showing videos

```blade
<x-video-demo::player slug="post-a-job" />                    {{-- the version that fits the screen --}}
<x-video-demo::player slug="post-a-job" device="mobile" />
<x-video-demo::player slug="post-a-job" collapsible />        {{-- a slim bar that opens into the video --}}
<x-video-demo::help />                                        {{-- in a layout: the videos that show the current page --}}
<x-video-demo::search placeholder="How do I…" />
```

- The library is at `/help/videos` (public videos) and `/admin/videos` (private ones too); prefixes and middleware are under `library` in the config. Each video page shows a written guide made from its plan, and `/help/videos/sitemap.xml` lists the public videos for search engines.
- `VideoDemo::library()`, `search($query)`, `forPage($routeName)` and `url($entry)` return the published videos for custom pages.
- Components render nothing for a video that isn't published, so they are safe to add before the video exists.

## Where published videos live

Production reads `manifest.json` and the files from `VIDEO_DEMO_DISK`. The machine that publishes and production must use the same disk:

- `VIDEO_DEMO_DISK=s3` (S3, R2…) with the same bucket settings in both `.env` files. Public videos need a bucket browsers can read.
- `VIDEO_DEMO_DISK=video-demo-repo` keeps them in `public/video-demo`: commit that folder and they deploy with the code.
- The default, `public`, stays on the machine that published, so production's library would be empty.

## A separate frontend

For a Vue, React or other frontend on its own address that uses this app as its API:

```dotenv
VIDEO_DEMO_FRONTEND_URL=http://localhost:5173   # where videos are recorded
VIDEO_DEMO_SIGN_IN=sanctum-token                # session (default, also Sanctum cookie sign-in), sanctum-token, jwt or custom
VIDEO_DEMO_TOKEN_KEY=token                      # the localStorage key the frontend reads its token from
VIDEO_DEMO_FRONTEND_PATH=../web                 # the frontend's repository: the AI reads its src/ under frontend/
```

For any other sign-in (Passport, headers, sessionStorage, the app's own session after SSO), set `custom` and register it:

```php
use Ogunsakin01\VideoDemo\Facades\VideoDemo;
use Ogunsakin01\VideoDemo\Recording\SignIn;

VideoDemo::signInUsing(fn ($user) => SignIn::header('Authorization', 'Bearer '.$user->createToken('video-demo')->accessToken));
```

Show published videos inside the frontend with `<script src="{APP_URL}/help/videos/embed.js" defer></script>` and `<video-demo-player slug="post-a-job"></video-demo-player>` (public videos). `APP_URL` stays this app's address. With `VIDEO_DEMO_FRONTEND_PATH` the AI reads the frontend's code (under `frontend/`) and `--frontend-changed=<ref>` on `verify`, `refresh` and `preview` flags videos a frontend change touches; without it, describe flows with the frontend's page paths and the AI looks at the pages for labels. `<video-demo-help></video-demo-help>` lists a page's videos. The frontend must call the API at `APP_URL` (or through its own address, such as Vite's `/api` proxy): only those requests reach the sample database. A change a step sends (POST, PUT, PATCH, DELETE) that the app refuses with a 4xx fails that step with Laravel's message.

## After UI changes

```bash
php artisan video-demo:verify --changed=origin/main --json     # which published videos a change broke; no recording, no AI
php artisan video-demo:verify post-a-job --fix --json           # plan the broken ones again, record and republish
php artisan video-demo:preview --changed=origin/main --out=video-demo-previews   # record the videos a change touches (MP4, GIF, summary.md) without publishing
php artisan video-demo:refresh --changed=<commit deployed before> --fix --json   # on deploy: record again only the videos the change touched
```

A small break, such as a renamed button, can be fixed in the plan and recorded for free:

```php
$video = VideoDemo::find('post-a-job');
$plan = $video->plan;
$plan['steps'][3]['target']['name'] = 'Publish job';
VideoDemo::updatePlan($video, $plan);   // checked: refuses a plan that can't be recorded
VideoDemo::rerecord($video);
```

Plan steps have an `action` (`goto`, `sign_in`, `click`, `type`, `select`, `check`, `press`, `hover`, `scroll`, `wait`, `caption`, `card`, `pause`) and fill only what that action uses: `url` for `goto` and `sign_in` (the page to open once signed in, with `value` the type of user, or empty for the default user); `target` (one of `role` + `name`, `label`, `placeholder`, `text`, or `selector`, plus `frame` for iframes) for elements; `value` for typing, options and keys; `title`, `text` and `ms` for captions and cards; `say` for the voice-over line; `chapter`; and `only_on` to limit a step to some devices.

## More

```bash
php artisan video-demo:translate post-a-job fr es --publish --json   # the same video in other languages, from its plan
php artisan video-demo:export post-a-job --format=gif --out=docs/post-a-job.gif   # gif, square or vertical
php artisan video-demo:export post-a-job --format=pest --out=tests/Browser/PostAJobTest.php   # the plan as a Pest browser test
php artisan video-demo:clean --dry-run                                # free disk space
```

- Voice-over and music are off by default. `VideoDemo::voices()` and `VideoDemo::music()` list what works here; the free local voices need `php artisan video-demo:install-voice`.
- Sample data rules and test payment details (such as Stripe's `4242 4242 4242 4242`) go in `video-demo.ai.notes`.
- `APP_URL` must be the address the app opens at: the recorder uses it.
- Full documentation: https://github.com/ogunsakin01/laravel-video-demos/wiki
