# Changelog

All notable changes to this package are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [semantic versioning](https://semver.org/). While the version is 0.x, minor releases may include breaking changes; they will be listed here.

## v0.5.1 - 2026-10-14

### Changed

- Code comments and the config file's notes rewritten in Laravel's style: short docblocks on every method, inline comments only where they explain why, and a note on every config setting. No behaviour changes.
- The config file names the right pricing page for Claude, and says the default model must have an API key.

## v0.5.0 - 2026-10-14

### Added

- Bulk publish: tick videos on the studio's list to publish or unpublish them together.
- The start page is a searchable list of the pages the chosen user can open, with their headings, found by opening each GET route that needs no parameters in a browser. Any path can still be typed.

### Changed

- "Record as" lists types of user (Admin, Member…) instead of individual users. Types are found from spatie/laravel-permission, Laratrust, Bouncer, a `role_id` or role column, or an `is_admin` flag, or listed in `recorder.user_types`. A user of the type is signed in for the recording, preferring one without other roles. `generate --as` takes a type. Videos already saved with a user keep it.
- The busy state on the AI buttons is a ring spinner instead of the icon turning.

### Fixed

- spatie/laravel-permission roles weren't found, so "Record as" offered no roles in apps using it.

## v0.4.0 - 2026-10-14

Run `php artisan migrate` after updating (a new migration), and start a worker for the package's queue: `php artisan queue:work video-demo`.

### Added

- **Spending limit per video**, in the form and as `generate --max-cost`. The AI is told its budget, sees how many requests it has left, and stops before a request that would pass the limit. The form warns when the lowest estimate is already above it.
- **Max steps per video** (`--max-steps`): plans with more steps are sent back to the AI to shorten.
- **Stop** a video while it is being made: at once if queued, otherwise before the next AI request or by closing the browser mid-recording. Stopped videos can be tried again.
- **Record as** any user, a guest, or the default user. Apps using spatie/laravel-permission get users listed by role. Also `generate --as=guest|<id or email>`.
- **Page checks before any AI is spent.** The start page is opened before planning and every page in the plan before recording, as the chosen user. Pages that return an error, redirect to sign-in, throw a JavaScript error or don't load stop the video, and are listed at the top of its page with Laravel's error message. An error from the app during recording also stops it, instead of paying for repairs that can't help.
- **Embed code** on a published video's page and in the staff library: the Blade component and a plain HTML `<video>` tag, each with a Copy button.
- **Ideas are saved** in a new `video_demo_ideas` table. "Suggest more" adds new ideas without repeating existing ones, "Replace all" starts over, ideas can be deleted, and ideas made into videos link to them. Also `video-demo:suggest --replace`.
- **The package's own queue connection**, `video-demo`: your default connection with a two-hour `retry_after`, on its own queue, so long videos aren't started a second time (and paid for twice) when they pass your queue's `retry_after`. The studio warns when the queue is `sync` or a chosen connection's `retry_after` is too short.
- `VIDEO_DEMO_NAME` for the name on the studio and library pages (it was always "APP_NAME videos").
- The one-hour prompt cache for long recordings, so repairs after a long recording still read the cache.
- README: `.env` examples for OpenAI, Gemini and Ollama with prices, "Lowering your AI costs", the price table, and an "Upgrading" section.
- The same menu on every studio page, with the current page marked. "View library" opens in a new tab.
- The package description on Packagist and GitHub names every supported provider, not only Claude.

### Changed

- **The default model is now the cheapest reliable one** whose provider has a key: Claude Sonnet 5.5 (was Opus). Haiku is offered but not picked by default. `VIDEO_DEMO_MODEL` sets your own.
- Videos are queued on the `video-demo` queue: run `php artisan queue:work video-demo`. Set `VIDEO_DEMO_QUEUE_CONNECTION` to use a connection of your own.
- The studio only offers models whose provider has a key, and no longer asks for `ANTHROPIC_API_KEY` when another provider is set up.
- Library video addresses on local disks are paths (`/storage/video-demo/…`), so videos play even when `APP_URL` differs from the address the app is opened at.
- Cost estimates allow for the extra requests less reliable models need.

### Fixed

- Claude Sonnet 5.5's cache-read price was $0.20 per million tokens; it is $0.10. Costs recorded with Sonnet were slightly too high.
- Claude Haiku 5.5 requests with prompts over 100K tokens are charged at five times the base price, as Anthropic bills them.
- Models whose names contain dots, such as `gpt-6.1-sol`, weren't found in `ai.models`.
- On phones, the studio menu sits on its own row and scrolls sideways instead of overflowing the screen.

## v0.3.0 - 2026-10-14

### Fixed

- Generating a video failed with "Schemas contains too many parameters with union types": the plan's tool schema had 17 nullable fields, one over Anthropic's limit of 16. The schemas now have none; unused fields are empty instead.
- On phones, the studio's header no longer overflows the screen.

### Added

- "Try again" on a failed video, at the top of its page and in the error box: a new plan with the same settings, or the same plan recorded again when recording failed.
- Ideas: the AI suggests the videos worth making first, from the app's routes, styles and videos already made. "Make this video" opens the new-video form filled in. Also `video-demo:suggest --json`.
- The new-video form can be filled in from the address: `prompt`, `title`, `start_path`, `target_seconds` and `style`.
- Docs for `VIDEO_DEMO_FFMPEG`: using an ffmpeg that isn't on the PATH, including a wrapper script for bundled copies such as Remotion's.
- An Examples page in the studio, with sample videos made by the package in each style and screen size, and a "Make one like this" button that opens the new-video form with that style.

## v0.2.0 - 2026-10-14

### Added

- `video-demo:generate`, `video-demo:list` and `video-demo:publish` commands, with `--json` output, so scripts and AI agents can make and publish videos without the studio's page.
- Laravel Boost guidelines, so coding agents in apps that install the package know how to use it.
- `llms.txt`, a summary of the package for AI models, and `AGENTS.md` for agents working on the package.
- The install command near the top of the README, and how to install ffmpeg.

## v0.1.0 - 2026-10-14

The first release.

### Added

- Studio, for making videos on a developer's machine: describe a flow, choose the length, style, versions, model and effort, and see the estimated cost first.
- "Complete with AI", which turns a short note into a title, description, start page and length.
- Planner: Claude reads the app's routes and allowed code folders and writes a step-by-step plan, then fixes it when a step fails in the browser.
- Recorder: plays a plan with Playwright, with a cursor or touch taps, captions and title cards, and encodes it with ffmpeg.
- Versions for desktop, TV, tablet and mobile from one plan, with steps that can be limited to some screens.
- Styles, seeded from config and editable in the studio.
- Library pages for production, with search and requests for missing videos, plus `<x-video-demo::player>` and `<x-video-demo::search>` components.
- `video-demo:install` and `video-demo:refresh` commands.
- Per-video cost from the API's token counts, and a spending limit per video.
- Laravel Pint and PHPStan (Larastan, level 5) in CI, with `composer check` to run everything locally.
- Animated previews of the tour videos in the README, since GitHub doesn't play videos stored in a repository.
- Other AI providers through Prism: OpenAI, Gemini, Mistral, DeepSeek, Groq, xAI, OpenRouter and local Ollama models. Claude stays the default, through the Anthropic SDK.
- Models without prices work, with their cost shown as unknown.
- A `viewVideoDemoStudio` gate: outside local, only users it allows can use the studio, and without it the studio is closed.
- Secrets that look like API keys, tokens, private keys or long passwords are hidden before code or page snapshots are sent to the AI, with custom patterns in `ai.code.redact_patterns`.
- Plan steps can target elements inside iframes, such as embedded payment card fields, and failed steps show the model each iframe's contents.
- Videos play at twice the recorded speed by default (`recorder.speed`), with captions, cards and pauses held longer so they stay readable.
- An "art" setting for styles: abstract line art that draws itself on behind title cards, with words rising into place. A new "Promo ad" default style uses it.

### Fixed

- The recorder now finds its user through any guard's user provider, not only guards that expose `getProvider()`.
- A work disk that isn't local (such as S3) is refused with a clear message, instead of failing during recording.
