# Changelog

All notable changes to this package are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [semantic versioning](https://semver.org/). While the version is 0.x, minor releases may include breaking changes; they will be listed here.

## v0.10.1 - 2026-10-15

### Added

- **Improve with AI** in Make it again rewrites the video's description from how it went: its plan, the steps that failed, the pages that didn't work and how long it came out. Optionally say what should be better, such as "shorter, and end on the saved rating". It fills in the description, length and start page, lists each change and why, and turns on a new plan; nothing starts until you press the button. About a cent or two, added to the video's cost.

## v0.10.0 - 2026-10-14

### Added

- **Delete with a warning.** Deleting a video, from its page or several at once from All videos, first shows where each is used: published in the library, and every page in your code that shows it (a player tag or its file address), by file and line. `VideoDemo::delete()` and `VideoDemo::embeds()` do the same from code.

### Changed

- **Make it again is one form**, laid out like the New video form, with one button. An **Ask the AI for a new plan** toggle chooses between recording the same plan again for free (with your new style, versions, speed and limit) and a new plan; it turns on by itself when you change something only a new plan can use. Before, a style chosen there was ignored when you pressed "Record the same plan again".
- **Documentation moved to the [wiki](https://github.com/ogunsakin01/laravel-video-demos/wiki)**, with a setup checklist and a troubleshooting page. The README is now a short overview with a quick start. The README opens with the dark-mode promo video.

## v0.9.1 - 2026-10-14

### Changed

- **Make it again** can change the video's style too, along with the description, length, effort, limits, speed and sample data.
- **The default spending limit is $0.50** a video, down from $2. Change it for one video in the form or with `--max-cost`, or for every video with `VIDEO_DEMO_MAX_COST`. Videos that set their own limit keep it.

### Fixed

- Updating the package no longer rebuilds the sample database. It was fingerprinted by the files' modification times, which `composer update` changes even when a migration is the same; it now goes by their contents.
- A slow image or script from another site no longer fails a recording with "page.goto: Timeout 30000ms exceeded". The recorder waits for the page itself (up to a minute), then gives images and scripts up to 10 seconds more without failing. The page check does the same.
- The AI is told to use images the app already has for sample data, not image hosting sites, which can be slow.

## v0.9.0 - 2026-10-14

### Changed

- **Videos are recorded on sample data by default**, on the form, from the facade and from the command line. To record on your own local data, untick **Record on sample data**, pass `--own-data` to `video-demo:generate`, `'sample_data' => false` to `VideoDemo::make`, or set `VIDEO_DEMO_SAMPLE_DATA=false` for the whole app. Videos made before keep their setting. If the sample database can't be built, the error says how to record on your own data instead.
- On the video page, **Make it again** is in the main column, under Details, instead of the narrow side column.

### Fixed

- Checkbox rows (Record on sample data, and Zoom and Highlight on the Styles page) line up with their labels again.

## v0.8.3 - 2026-10-14

### Added

- A video made before sample data, or without it, can switch: **Try again on sample data** on the "A page in your app has a problem" message, a **Record on sample data** box under "Make it again", and `VideoDemo::retry($video, sampleData: true)`. Switching always makes a new plan. The video page shows which data it was recorded on, and the error for a failing page suggests sample data.

## v0.8.2 - 2026-10-14

### Fixed

- The test suite's fake Anthropic server now asks the system for a free port and checks that it started, retrying if not, so tests no longer fail now and then on CI. Tests only; the package itself is unchanged.

## v0.8.1 - 2026-10-14

### Changed

- **One setup command.** `php artisan video-demo:install` now does it all, and is safe to run again after each upgrade: it publishes the config (keeping yours), runs the package's own migrations, installs Playwright and Chromium, builds the sample database with a user of each type, and restarts the queue workers. `--no-browser` and `--no-sample-data` skip those steps. A sample database that can't be built is a warning, not a failure.

### Fixed

- The sample-data test no longer depends on Testbench's cached packages, which made it fail on a fresh CI machine.

## v0.8.0 - 2026-10-14

Run `php artisan migrate` after updating.

### Added

- **Record on sample data**: record a video against a temporary SQLite database instead of your own data. A base database is built once from your migrations and seeders, with a user of each type, and copied for each video (`php artisan video-demo:sample-database` builds it ahead of time; it is rebuilt when a migration, seeder or factory changes). While planning, the AI reads the routes' middleware, your policies and gates, and adds or updates the records the flow needs, such as a permission, a profile photo or tickets that belong to the user. Pages that fail before recording, and app errors while recording, go back to the AI to fix. Only the recorder's browser uses the database. On the form, with `--sample-data`, or `'sample_data' => true` in `VideoDemo::make`.

### Changed

- The studio and library pages have their own browser tab icon, a play button, instead of the app's favicon. Set `VIDEO_DEMO_FAVICON=app` to use the app's favicon (now also found as `favicon.webp` or `favicon.jpg`), or an image URL to use that image.
- On wide screens, the estimated cost and the Generate video button on the New video page stay in view while you scroll the form.

## v0.7.1 - 2026-10-14

### Fixed

- Getting suggestions or planning a video no longer fails with "Object of class Closure could not be converted to string" in apps whose controllers add middleware with `$this->middleware(function ...)`.

## v0.7.0 - 2026-10-14

Run `php artisan migrate` after updating: it adds the new style settings, and the Spotlight style for apps that already have styles.

### Added

- **The Spotlight style**: the app recorded in dark mode, the camera zooming in on each button and field as it is used, an outline around what is being used with the rest dimmed, and title cards and captions whose words rise into place. A polished feature reel, made from the same plan as any other video.
- Three new style settings, for any style, on the Styles page: **App appearance** (record the app in light or dark mode), **Zoom** and **Highlight**. The zoom is added after recording, so clicks and typing behave exactly as before, and it works with any ffmpeg build; if it ever fails, the video is kept without it.
- **The `VideoDemo` facade**, for apps that build their own screens or make videos from their own code: `make`, `estimate`, `stop`, `retry`, `rerecord`, `publish`, `unpublish`, `videos`, `find`, `library`, `search`, `url`, `suggest`, `ideas`, `styles`, `userTypes`, `models`, `pages` and `checkPages`.
- **The `VideoStatusChanged` event**, fired whenever a video moves to another status, such as Ready or Failed.
- **`php artisan video-demo:clean`**: frees disk space by removing raw recordings, temporary files and files of deleted videos; `--browser` also removes the recorder's Playwright install; `--dry-run` lists what would go.
- The studio and library use your app's own favicon (or `VIDEO_DEMO_FAVICON`).
- The Examples page shows the same video in each of the six built-in styles, so they are easy to compare.

### Changed

- `video-demo:generate` makes videos through the facade, so the command and your own code check options the same way.

## v0.6.0 - 2026-10-14

Run `php artisan migrate` after updating: there is a new column for each video's speed.

### Added

- Playback speed for each video: choose 1× (real time), 1.25×, 1.5×, 2× or 3× in the new-video form, when asking for a new plan, or when recording the same plan again (free). Also `video-demo:generate --speed=1.5`. `VIDEO_DEMO_SPEED` is now the default for new videos.

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
