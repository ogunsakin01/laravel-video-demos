# Laravel Video Demos

[![Latest version on Packagist](https://img.shields.io/packagist/v/ogunsakin01/laravel-video-demos.svg?style=flat-square)](https://packagist.org/packages/ogunsakin01/laravel-video-demos)
[![Tests](https://img.shields.io/github/actions/workflow/status/ogunsakin01/laravel-video-demos/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ogunsakin01/laravel-video-demos/actions/workflows/tests.yml)
[![Total downloads](https://img.shields.io/packagist/dt/ogunsakin01/laravel-video-demos.svg?style=flat-square)](https://packagist.org/packages/ogunsakin01/laravel-video-demos)
[![PHP 8.3+](https://img.shields.io/badge/php-8.3%2B-777bb4.svg?style=flat-square)](composer.json)
[![Laravel 12 and 13](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20.svg?style=flat-square)](composer.json)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE.md)

Describe a flow in your Laravel app and get a recorded how-to video of it. An AI model (Claude by default, or OpenAI, Gemini, a local model and others) reads your app's code and plans the walkthrough. Playwright then records it in a real browser against your local copy of the app, with a cursor, captions and title cards, and plays it back at twice the speed so it feels snappy. You choose the length, the style (from step-by-step tutorials to short ads with animated line art) and the screens (desktop, TV, tablet, mobile), and you see the cost before anything is spent.

Videos are made on your machine and published to any Laravel disk. Your production app shows them in a ready-made help library, or inside your own pages with a Blade component.

```bash
composer require ogunsakin01/laravel-video-demos
php artisan video-demo:install
php artisan migrate
```

See [Installation](#installation) for the `.env` settings and the queue worker.

## See it

These tours were recorded by the package itself, from the demo app in [`workbench/`](workbench), in the "Promo ad" style. GitHub doesn't play videos stored in a repository, so the previews below are GIFs of the same videos; the links under them are the full MP4s.

### The studio, where you make videos

[![The studio](docs/studio-tour.gif)](docs/studio-tour.mp4)

[Full studio tour (MP4, about a minute)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/studio-tour.mp4)

### The library, where your users watch them

| Desktop | Mobile |
| --- | --- |
| [![The library on desktop](docs/library-tour.gif)](docs/library-tour.mp4) | [![The library on mobile](docs/library-tour-mobile.gif)](docs/library-tour-mobile.mp4) |

[Full library tour (MP4)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/library-tour.mp4) · [Mobile version (MP4)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/library-tour-mobile.mp4)

The videos in the demo library all play one sample clip, recorded from the demo app's "Post a job" page. The demo app answers "Complete with AI" with a fixed reply, so the tours could be recorded without an API key.

## How it works

1. **You describe the video** in the studio: a few words are enough, and **Complete with AI** turns them into a full description with a title, start page and length. Pick a length, a style and the screens to record.
2. **The AI reads your code.** The planner gives the model your route list and read-only access to the folders you allow (by default `app`, `routes`, `resources/views`, `resources/js`, `lang`), never `.env`, and with anything that looks like a secret hidden. It writes a step-by-step plan: go to a page, click a button, type into a field, show a caption.
3. **Playwright records the plan** against your local app, once per screen you chose. A step can be limited to some screens, such as opening the menu on mobile, and can reach into iframes, such as embedded card fields.
4. **If a step fails,** the model gets the error and the page's accessibility tree, fixes the plan, and the recorder tries again.
5. **ffmpeg encodes** each recording to MP4 with a poster image, at twice the recorded speed.
6. **You watch it and publish it.** The video and its versions are copied to your storage disk and listed in a manifest file that your production app reads.

The plan is stored with the video. After your app's look changes, `php artisan video-demo:refresh` records every published video again from its plan, at no AI cost.

## Requirements

- PHP 8.3+ and Laravel 12 or 13
- Node.js 18+ and ffmpeg on the machine where you make videos, not in production (`brew install ffmpeg` on a Mac, `apt install ffmpeg` on Ubuntu); or [use another copy](#using-an-ffmpeg-that-isnt-on-your-path) with `VIDEO_DEMO_FFMPEG`
- An [Anthropic API key](https://console.anthropic.com/), or another provider through [Prism](#ai-providers)

## Installation

```bash
composer require ogunsakin01/laravel-video-demos
php artisan video-demo:install   # publishes the config, installs Playwright and Chromium
php artisan migrate
```

Add to `.env` on your machine:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...      # or another provider's key, see "AI providers"
# The user the recorder signs in as by default, by id or email (each video can pick another)
VIDEO_DEMO_LOGIN_AS=demo@example.com
# Where your local app is served, if not APP_URL
VIDEO_DEMO_BASE_URL=https://myapp.test
# The name on the studio and library pages (default: "APP_NAME videos")
VIDEO_DEMO_NAME="Acme help videos"
```

If you serve the app with `php artisan serve`, give it a few workers (`PHP_CLI_SERVER_WORKERS=4` in `.env`): the studio opens your pages in a browser while answering a request, and a single worker can't do both.

Make sure `APP_URL` is the address you open the app at (`https://myapp.test`, not `http://localhost`): the recorder and the library's links use it.

Start a worker for the package's queue and open the studio at `/video-demo/studio`:

```bash
php artisan queue:work video-demo
```

Videos run on their own queue connection, `video-demo`: a copy of your default connection whose `retry_after` is two hours. Without it, a video taking longer than your queue's `retry_after` (90 seconds in a new Laravel app) would be started a second time and paid for twice. Your other workers don't pick these jobs up. To use a connection of your own instead, set `VIDEO_DEMO_QUEUE_CONNECTION` and give it a `retry_after` of at least 1800; the studio warns you if it is shorter, or if the queue is `sync`.

The worker loads the package's code when it starts, so restart it after updating the package (see [Upgrading](#upgrading)).

### Using an ffmpeg that isn't on your PATH

The recorder runs `ffmpeg` by default. If it isn't installed system-wide, or you'd rather use a copy that came with another tool (Remotion bundles one, for example), point the package at it:

```dotenv
VIDEO_DEMO_FFMPEG=/full/path/to/ffmpeg
```

Some bundled builds also need their own library folder to start. Remotion's macOS ffmpeg, for instance, fails with "signal 6" unless `DYLD_LIBRARY_PATH` points at its folder. Wrap it in a small script and point `VIDEO_DEMO_FFMPEG` at the script:

```bash
# ~/.local/bin/ffmpeg-remotion  (then: chmod +x ~/.local/bin/ffmpeg-remotion)
#!/bin/sh
FF=/path/to/your-app/node_modules/@remotion/compositor-darwin-arm64   # -x64 on Intel Macs
DYLD_LIBRARY_PATH="$FF" exec "$FF/ffmpeg" "$@"
```

```dotenv
VIDEO_DEMO_FFMPEG=/Users/you/.local/bin/ffmpeg-remotion
```

`php artisan video-demo:install` warns if it can't run ffmpeg.

## Upgrading

1. **Move to the new version.** While the package is below 1.0, each minor version (0.2, 0.3…) may include breaking changes, so Composer's `^` keeps you on the one you installed: `composer update` won't move `^0.2` to 0.3. Ask for the new one by name:

   ```bash
   composer require ogunsakin01/laravel-video-demos:^0.7
   ```

   Patch releases (0.7.0 to 0.7.1) arrive with a normal `composer update`.
2. **Restart your queue workers.** A running `php artisan queue:work` keeps the code it loaded when it started, so without a restart videos are still made by the old version, even though the studio pages show the new one:

   ```bash
   php artisan queue:restart     # running workers stop after their current job
   php artisan queue:work video-demo
   ```

   From 0.4, videos run on the package's own `video-demo` queue, so start a worker for it as above; a plain `queue:work` no longer picks them up.

   With Horizon, run `php artisan horizon:terminate`; with Supervisor, it starts the workers again for you. Do the same in production after a deploy.
3. **Run new migrations, if any:** `php artisan migrate`.
4. **Check the [changelog](CHANGELOG.md)** for what changed. If you published the config or views, compare them with the new versions: new settings work without being in your published config, but changes to the published views won't reach you.
5. **If your app caches its config or views,** clear them: `php artisan optimize:clear`.

## The studio

The studio is where videos are made. It is only registered when `APP_ENV` is `local` (or `VIDEO_DEMO_STUDIO=true`), and never in production unless you set `studio.allow_in_production`. Its routes, including the signed sign-in link the recorder uses, do not exist when it is off.

- **Videos**: every video with its status, length and cost. Tick several to **Publish** or **Unpublish** them together; videos that aren't ready, or are already published at their latest version, are skipped.
- **New video**: describe the flow, or use **Complete with AI**. Choose the length (a target: captions and pauses stretch or shrink to fit), the versions to record, a style, the model and the effort. The estimated cost updates as you change them.
  - **Record as**: the type of user the video shows the app as, such as Admin or Member, or a guest (signed out). Types come from the way your app models roles: [spatie/laravel-permission](https://github.com/spatie/laravel-permission), Laratrust, Bouncer, a `role_id` or `role`/`type` column on users, or an `is_admin` flag. For the recording, a user of that type is signed in, preferring one with no other roles (so a Member video isn't recorded by someone who is also an admin) and the default user when they qualify. If your app does roles another way, list the types in `recorder.user_types`: `'Admin' => 'admin@example.com'`.
  - **Start page**: search the pages the chosen type of user can open, by title or address, or type any path (`/jobs/5`). The list comes from your GET routes that need no parameters, each opened as that user to keep the ones that return a page; the title is the page's heading. Logout, delete, export and API routes are never opened.
  - **Spending limit**: the most this video may cost ($2 unless you change it, or `limits.max_cost_per_video`). The AI is told its budget, sees how many requests it has left, and stops before a request that would go over. The form warns you if even the lowest estimate is above the limit.
  - **Max steps**: the most steps the plan may have. Fewer steps make a shorter, cheaper plan.
  - **Playback speed**: how much faster the video plays than it was recorded, from 1× (real time) to 3×. See [Speed](#speed).
- **Video page**: watch each version, read the plan, see the actual cost and progress, publish or unpublish, record the same plan again (free), or ask the AI for a new plan.
  - **Stop** a video while it is being made: at once if it is still queued, otherwise before the next AI request or by closing the browser mid-recording. You pay only for what was used so far. **Try again** starts it over.
  - **Pages are checked first.** Before planning, the start page is opened as the chosen user; before recording, every page in the plan is. A page that returns an error, sends the user to sign in, throws a JavaScript error or doesn't load stops the video there, with the problem listed at the top of the page (including Laravel's error message), so no AI is spent on a page that can't work. An error from your app during recording also stops it, instead of asking the AI to "repair" a plan that was right.
  - **Embed**: once published, copy the code to show it anywhere: the Blade component for your own pages, or a plain HTML `<video>` tag.
- **Styles**: how the AI writes each video (its "direction") and how cards and captions look: light or dark, accent colour, captions at the top or bottom, pace, and **art**. With abstract art, title cards get line art that draws itself on and drifts, and the words rise into place, like an ad. A style can also set:
  - **App appearance**: record the app in light or dark mode, for apps that follow the device's setting.
  - **Zoom**: the camera eases in on each button and field as it is used, and pulls back out for captions, title cards and new pages.
  - **Highlight**: an outline around what is being used, with the rest of the page dimmed.

  The defaults are Clean walkthrough, Quick tip, Detailed tutorial, Product showcase, Promo ad and **Spotlight**, which turns all three on, in dark mode, for a polished feature reel. They come from `config/video-demo.php` and are copied into the database the first time. Add and edit your own on this page.

On a developer's machine the studio is open. Anywhere else it is enabled (a staging server, say), only users your `viewVideoDemoStudio` gate allows can use it, and without that gate it is closed:

```php
Gate::define('viewVideoDemoStudio', fn ($user) => $user->isAdmin());
```
- **Ideas**: not sure what to make first? The AI reads your routes and suggests the videos your users most need, each with a description, length, start page and style. **Make this video** opens it in the form, filled in. Ideas are saved: **Suggest more** adds new ones without repeating those you have, **Replace all** starts over, and ideas made into videos are marked with a link to the video. It costs about a cent or two.
- **Examples**: the same short video made in each built-in style, so you can compare them, with **Make one like this** to start one in that style. The videos stream from the package's GitHub repository; nothing is stored in your app. Turn the page off with `VIDEO_DEMO_EXAMPLES=false`.
- **Requested by users**: searches in your library that found nothing. Click one to start a video.

## From the command line

Everything the studio does is also a command, so scripts and AI agents can make videos too. Add `--json` for output a program can read.

```bash
# Waits for the video, printing an estimated cost first; exits non-zero if it fails
php artisan video-demo:generate "How a recruiter posts a job, from the jobs page to a live listing" \
    --length=60 --style=clean --devices=desktop,mobile

# An ad-style video, published to the library when it's ready
php artisan video-demo:generate "Show off posting a job" --style=ad --publish

php artisan video-demo:suggest                     # ideas for the videos worth making first
php artisan video-demo:list
php artisan video-demo:publish post-a-job          # or --unpublish
php artisan video-demo:refresh                     # re-record published videos after UI changes, no AI cost
php artisan video-demo:clean                       # free disk space (see Disk space)
```

`video-demo:generate` also takes `--title`, `--model`, `--effort`, `--start=/path`, `--private`, `--max-cost=0.50` (the spending limit in dollars), `--max-steps=20`, `--speed=1.5`, `--as=` (a user type such as `admin`, `guest`, or a user id or email) and `--queue` (queue it and return straight away). With `--json`, broken pages found before recording are listed under `issues`. `video-demo:suggest --replace` starts the ideas over.

## The library

Your users watch videos at `/help/videos` (public) and `/admin/videos` (private videos too, behind `auth`). Both prefixes, their middleware and their domains are in the config. The private library uses Laravel's `auth` middleware, which sends signed-out visitors to your app's `login` route; if your app signs people in another way, change `library.private.middleware`. The library reads the published manifest, so production needs only the storage disk, not the studio's database.

When a search finds nothing, users can ask for a video. Requests are stored in production. To see them in your local studio, set the same `VIDEO_DEMO_REQUESTS_TOKEN` in both environments and `VIDEO_DEMO_PRODUCTION_URL` locally.

### In your own pages

```blade
{{-- Plays the version that suits the viewer's screen --}}
<x-video-demo::player slug="post-a-job" />

{{-- Always the mobile version --}}
<x-video-demo::player slug="post-a-job" device="mobile" />

{{-- A search box that opens the library --}}
<x-video-demo::search placeholder="How do I…" />
```

Each published video's page in the studio (and in the staff library) has this code ready to copy, plus a plain HTML `<video>` tag for pages outside your app.

Videos on a local disk (such as `public`) are linked by path, `/storage/video-demo/…`, so they play at whatever address the app is opened at, even if `APP_URL` is out of date. Disks on another host, such as S3 or a CDN, keep their full address.

To restyle the library pages, publish the views:

```bash
php artisan vendor:publish --tag=video-demo-views
```

## Using it from your own code

Everything the studio does is available through the `VideoDemo` facade, so you can build your own screens, make videos from your own jobs, or skip the studio entirely.

```php
use Ogunsakin01\VideoDemo\Facades\VideoDemo;

// Queue a video. Every option is optional; a bad one throws InvalidArgumentException.
$video = VideoDemo::make('How a manager approves a holiday request', [
    'title' => 'Approve a holiday request',
    'start' => '/time-off',
    'length' => 60,               // seconds
    'style' => 'spotlight',       // a style key or name
    'devices' => ['desktop', 'mobile'],
    'model' => 'claude-sonnet-5-5',
    'max_cost' => 0.50,           // US dollars
    'max_steps' => 20,
    'speed' => 1.5,
    'as' => 'manager',            // a user type, 'guest', or a user id or email
    'private' => false,
    'queue' => true,              // false makes it now, in this process
]);

VideoDemo::estimate('claude-sonnet-5-5', 'medium', 60);  // ['low' => …, 'expected' => …, 'high' => …]
VideoDemo::stop($video);
VideoDemo::retry($video);                     // a new plan
VideoDemo::rerecord($video, speed: 1.0);      // the same plan again, at no AI cost
VideoDemo::publish($video);                   // or unpublish()

VideoDemo::videos();                          // every video, newest first
VideoDemo::find('approve-a-holiday-request'); // by id or slug
VideoDemo::library('public');                 // published videos, as the library shows them
VideoDemo::search('holiday');
VideoDemo::url($entry, 'video', 'mobile');    // a published video's MP4 or poster address

VideoDemo::suggest();                         // ideas from the AI, saved
VideoDemo::ideas();
VideoDemo::styles();
VideoDemo::userTypes();                       // the user types found from your roles
VideoDemo::models();
VideoDemo::pages(as: 'manager');              // pages that user type can open, with their headings
VideoDemo::checkPages(['/time-off', '/reports'], as: 'manager');  // broken pages, at no AI cost
```

`Video` is an Eloquent model (`Ogunsakin01\VideoDemo\Models\Video`) with the status, plan, cost, log and recordings, so you can query it like any other. When a video's status changes, the package fires `Ogunsakin01\VideoDemo\Events\VideoStatusChanged`, with the video and the old and new status. Listen for it to send a notification when a video is ready, or to refresh your own screens:

```php
Event::listen(function (VideoStatusChanged $event) {
    if ($event->to === VideoStatus::Ready) {
        $event->video->title; // notify someone, publish it, …
    }
});
```

The library and the player work without the studio: `<x-video-demo::player slug="…" />` and `VideoDemo::library()` read the published videos.

## Disk space

The package itself is about 500 KB. The example videos are not part of it: the Examples page streams them from GitHub. What takes space is on the machine that makes videos:

- **The recorder's browser.** `video-demo:install` installs Playwright and Chromium (a few hundred MB, shared with other projects that use Playwright).
- **Working files.** Each video keeps its finished MP4s for the studio's preview and for publishing, and runs that stop early can leave raw recordings behind.

```bash
php artisan video-demo:clean --dry-run   # what would be removed, and how much it frees
php artisan video-demo:clean             # leftover working files: raw recordings, temporary files, files of deleted videos
php artisan video-demo:clean --browser   # also the Playwright install and its browser (video-demo:install puts them back)
```

Finished videos and the library are never touched. Production needs neither the browser nor the working files: only the published videos on the storage disk.

## Publishing to production

Videos are made locally but watched in production, so both need to reach the same files. Use a shared disk such as S3 or R2:

```dotenv
VIDEO_DEMO_DISK=s3
```

Private videos get temporary links on disks that support them. On a public disk, anyone with a private video's address can still open it, so use a private bucket for private videos.

## Versions for each screen

| Version | Size | Notes |
| --- | --- | --- |
| Desktop | 1280×800 | On by default |
| TV | 1920×1080 | |
| Tablet | 820×1180 | Touch: taps instead of a cursor |
| Mobile | 390×844 | Touch, with the page's mobile layout |

The AI writes one plan for all of them. Each extra version only adds recording time on your machine. Sizes and defaults are in the `devices` and `default_devices` config.

### Speed

Videos play faster than they were recorded, which makes clicks, typing and page changes snappy. Captions, title cards and pauses are held longer while recording, so they still stay up long enough to read.

Choose the speed for each video in the new-video form (1× real time, 1.25×, 1.5×, 2× or 3×), with `video-demo:generate --speed=1.5`, or when you record the same plan again, which costs nothing. New videos start at `VIDEO_DEMO_SPEED`, 2× unless you change it.

If your app shows videos, set `VIDEO_DEMO_BROWSER_CHANNEL=chrome` to record with installed Chrome: Playwright's own Chromium can't play MP4.

## What it costs

Recording and encoding run on your machine and cost nothing. Each app owner pays their AI provider for their own use, with their own key.

The studio shows an estimate before you generate, and each video records what it actually cost, from the token counts the API returns. Estimates from the package's estimator, for an app with about 80 routes:

| Model, effort | 30 seconds | 1 minute | 2 minutes |
| --- | --- | --- | --- |
| Claude Opus 5.5, high | ~$0.54 | ~$0.63 | ~$0.90 |
| Claude Opus 5.5, medium | ~$0.37 | ~$0.45 | ~$0.66 |
| Claude Sonnet 5.5, high (the default) | ~$0.27 | ~$0.32 | ~$0.45 |
| Claude Sonnet 5.5, medium | ~$0.19 | ~$0.23 | ~$0.33 |
| Claude Haiku 5.5, high | ~$0.02 | ~$0.02 | ~$0.03 |

Real videos land close to these: a 30-second Haiku video cost $0.015 and a 90-second Opus video with two versions cost $0.67, both including two repairs. "Complete with AI" costs about a cent.

Each video's cost is worked out from the token counts the API returns and the prices in the config, which match [Anthropic's price list](https://platform.claude.com/docs/en/about-claude/pricing) (per million tokens):

| Model | Input | Cache write (5 min / 1 hour) | Cache read | Output |
| --- | --- | --- | --- | --- |
| Claude Opus 5.5 | $4 | $5 / $8 | $0.20 | $20 |
| Claude Sonnet 5.5 | $2 | $2.50 / $4 | $0.10 | $10 |
| Claude Haiku 5.5 | $0.10 | $0.125 / $0.20 | $0.01 | $0.50 |

Haiku charges five times these prices for a request whose prompt (cached tokens included) is over 100K tokens, and the package counts that. Thinking is billed as output. Update the prices in the config if your provider's change. A model without prices still works, but shows its cost as unknown.

**The default model** is the cheapest one marked `reliable` in `ai.models` whose provider has a key: Claude Sonnet 5.5 out of the box. Haiku is cheaper but plans less reliably, so it is offered but not picked for you. Set `VIDEO_DEMO_MODEL` to choose your own default.

### Lowering your AI costs

- **Set a spending limit** on each video, or lower `limits.max_cost_per_video`. The AI plans within it and stops before going over.
- **Use Sonnet, or medium effort.** Sonnet at medium costs about a third of Opus at high, and plans most flows well. Try Haiku for short, simple videos.
- **Keep videos short** and **cap the steps**. Length and steps drive the number of requests.
- **Give a start page** and a precise description. The AI reads less code to find its way.
- **Narrow `ai.code.paths`** to the folders that matter, and put facts the code doesn't show in `ai.notes` so the AI doesn't search for them.
- **Fix broken pages first.** Pages are checked for free before any AI is used; a failed check costs nothing.
- **Re-record instead of re-planning.** After a design change, "Record the same plan again" and `video-demo:refresh` cost nothing.
- **Add versions, not videos.** Desktop, tablet and mobile share one plan, so extra versions add no AI cost.
- **Stop** a video the moment it is going wrong.

The package already keeps requests cheap: the conversation is cached (later requests pay the cache price for it), long recordings use the one-hour cache so repairs still hit it, and a failed step sends the AI only the part of the page around it.

## Configuration

Everything is in [`config/video-demo.php`](config/video-demo.php), with comments. The settings you are most likely to change:

| Setting | What it does |
| --- | --- |
| `ai.model`, `ai.effort` | Defaults for new videos |
| `ai.models` | The models offered, their provider and prices |
| `ai.code.paths`, `ai.code.deny` | What the AI may read |
| `ai.code.redact`, `ai.code.redact_patterns` | Hiding secrets before code is sent |
| `ai.notes` | Anything the AI should know that the code doesn't say, such as your sample data or test card numbers |
| `recorder.login_as` | The user the recorder signs in as by default |
| `recorder.user_types` | The types of user to record as, when they can't be found from your roles |
| `recorder.check_pages` | Check pages for errors before planning and recording (on by default) |
| `queue.connection`, `queue.retry_after` | The queue videos run on; leave the connection empty to use the package's own |
| `ui.name` (`VIDEO_DEMO_NAME`) | The name on the studio and library pages |
| `styles` | The styles offered at first |
| `devices`, `default_devices` | Screen sizes, and which are switched on |
| `recorder.speed` | How much faster videos play than they were recorded |
| `studio.gate` | Who may use the studio outside local |
| `limits` | The default spending limit, and request and repair limits per video |
| `ui.accent`, `ui.logo`, `ui.font_url` | The look of the studio and library |
| `ui.favicon` (`VIDEO_DEMO_FAVICON`) | The browser tab icon; your app's own favicon is used when it is empty |

## AI providers

Claude, through the official Anthropic SDK, is the default and gets the most out of the package: prompt caching (each request pays the cheap cached price for the conversation so far), effort control, strict schemas and fallbacks when a request is declined.

Other providers go through [Prism](https://github.com/prism-php/prism): OpenAI, Gemini, Mistral, DeepSeek, Groq, xAI, OpenRouter, and local models with Ollama.

```bash
composer require prism-php/prism:^0.100
php artisan vendor:publish --tag=prism-config   # then set your provider's key in config/prism.php
```

Set the provider's key in `.env`, as Prism reads it:

```dotenv
# OpenAI
OPENAI_API_KEY=sk-...
VIDEO_DEMO_MODEL=gpt-6.1-sol

# Google Gemini
GEMINI_API_KEY=...
VIDEO_DEMO_MODEL=gemini-3.8-flash

# Ollama, on your machine: no key, no cost
OLLAMA_URL=http://localhost:11434
VIDEO_DEMO_MODEL=qwen3-coder
```

Then add the model to `ai.models` in `config/video-demo.php` (publish it with `php artisan vendor:publish --tag=video-demo-config`), with its provider and, for cost estimates, its prices in US dollars per million tokens. These are the listed prices in October 2026; check your provider's pricing page:

```php
'models' => [
    // ...the Claude models
    'gpt-6.1-sol' => ['label' => 'GPT-6.1 Sol', 'provider' => 'openai', 'reliable' => true, 'input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10],
    'gemini-3.8-flash' => ['label' => 'Gemini 3.8 Flash', 'provider' => 'gemini', 'reliable' => false, 'input' => 0.75, 'output' => 3.75, 'cache_read' => 0.075],
    'qwen3-coder' => ['label' => 'Qwen3 Coder (local)', 'provider' => 'ollama', 'reliable' => false, 'input' => 0, 'output' => 0],
],
```

The studio offers every model whose provider has a key. Without `VIDEO_DEMO_MODEL`, new videos start on the cheapest model marked `reliable` that has a key.

The planner reads code and calls tools many times per video, so weaker models write plans that fail more often and need more repairs. Mark a model `reliable` once it has made a few videos well. Effort and prompt caching settings only apply to Claude; other providers cache in their own way.

## Payments and third-party services

- **Embedded fields**, such as Stripe Elements or PayPal buttons, sit in iframes. Plan steps can target them, and when a step fails the model is shown each iframe and what's inside it.
- **Hosted pages on another site**, such as Stripe Checkout, are reached by clicking through from your app.
- **Test mode only.** Put the provider's test card numbers or test accounts in `ai.notes` (for Stripe, `4242 4242 4242 4242`). Never use real payment details.
- **Things that arrive later**, such as a payment webhook, only work if your local app receives them, for example with `stripe listen --forward-to` your local URL. The plan's `wait` step then waits for the result.
- **Can't be automated:** CAPTCHAs, "Sign in with Google" and similar logins, codes sent by SMS, and email links. Sign the recorder in directly with `recorder.login_as`, or turn these off locally.

## Security

- **The studio** and the recorder's sign-in link only exist where the studio is enabled, and outside local only users your gate allows can use it.
- **Your code goes to your AI provider.** The model can only read the folders in `ai.code.paths`, never files matching `ai.code.deny`, and paths outside them, including `../` tricks, are refused. Anything that looks like a secret (API keys, tokens, private keys, long passwords in quotes) is replaced with `[redacted: …]` before it is sent, in code and in the pages shown when a step fails. Add your own patterns with `ai.code.redact_patterns`.
- **Prompt injection.** Text in your code, data or pages could try to give the model instructions. The model can only read the allowed folders and write plans, but plans run in a real browser as the recorder's user, so record against a disposable local database with sample data, never a copy of production.
- **Personal data.** Videos show whatever the screen shows, so use sample data.
- **Private videos** on a public disk can be opened by anyone who has the address; use a private bucket.
- **Your API key** stays in your local `.env` and is never needed in production.

Please report security problems privately; see [SECURITY.md](SECURITY.md).

## For AI agents

- **[Laravel Boost](https://laravel.com/docs/boost):** the package ships Boost guidelines, so coding agents in your app know how to make, publish and embed videos. Run `php artisan boost:install` after installing the package and choose it from the list.
- **Commands with JSON output:** an agent can run `video-demo:generate … --json`, read the result, fix what failed and run it again.
- **[llms.txt](llms.txt):** a short summary of the package for AI models.
- **[AGENTS.md](AGENTS.md):** instructions for agents working on the package itself.

## Developing the package

```bash
composer install
composer test
```

Two tests record real videos and are skipped unless Playwright is available:

```bash
VIDEO_DEMO_TEST_NODE_MODULES=/path/to/node_modules composer test
```

The demo app in `workbench/` has sample videos, styles and requests:

```bash
composer serve   # builds the demo data and serves it on port 8123
```

The tour videos above are plain plans in `workbench/tours`, recorded with the package's own recorder:

```bash
vendor/bin/testbench demo:record-tours --url=http://127.0.0.1:8123
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what changed in each release.

## Contributing

Bug reports, ideas and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for how to set up the package and its demo app, and please follow the [code of conduct](CODE_OF_CONDUCT.md).

## Credits

- [Damilola Ogunsakin](https://github.com/ogunsakin01)
- [All contributors](https://github.com/ogunsakin01/laravel-video-demos/graphs/contributors)

## License

MIT. See [LICENSE.md](LICENSE.md).
