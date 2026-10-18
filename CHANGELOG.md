# Changelog

All notable changes to this package are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [semantic versioning](https://semver.org/). While the version is 0.x, minor releases may include breaking changes; they will be listed here.

## v0.18.2 - 2026-10-18

### Added

- **Videos that sign in partway through.** A video that starts signed out (or as one type of user) can sign in where its flow needs an account, with a new `sign_in` step: out of view, as a ready-made user of a type (such as Member) or the default user, then on to the next page as them. So "a visitor browses the public pages, joins, and looks around the member area" shows the member area, instead of describing it over the sign-up form. The AI uses it on its own, is told an account made during the video can't get past email verification, and is told to show every page the voice-over describes. It shows in the plan editor, the written guide and the Pest test.
- **Cookie banners are accepted before recording.** Out of view, the recorder clicks the app's own "Accept all" (or "Accept", "Allow all", "I agree"… in the six languages, only inside something about cookies or consent) and keeps the choice, so the banner shows on no page of the video, the rehearsal, or the pages the AI looks at. `VIDEO_DEMO_COOKIE_BANNER=leave` shows it as it is; a CSS selector names the button when it isn't found.

### Fixed

- **Pages behind sign-in or email verification.** Opening one while signed out now says to add a sign-in step, and landing on an email verification notice (`/email/verify`, `/verify-email`) fails the step with the reason, so the rehearsal sends it back to the AI instead of the video carrying on over the wrong page.

- **Windows.** The AI can read the app's code there (every file was refused as outside the folders it may read), `--out` takes a path such as `D:\docs\video.gif`, finding where a video is shown, the view showing some text, the controllers behind a video's pages (so `--changed` flags it when they change) and the frontend's repository work with Windows paths, and `video-demo:work` and stopping a video work without Unix signals.

## v0.18.1 - 2026-10-18

### Added

- **A written guide under every video in the library.** Made from the video's plan, at no AI cost: chapters become headings, the steps read like "Click **Post a job**" and "Fill in **Job title** and **Location**", and captions explain what is shown. For people who would rather read, for screen readers, and for search engines, which index text and not video. What was typed isn't shown, as it is sample data. Videos published since v0.17.11 get one without being published again. The words around the labels come in English, French, Spanish, German, Portuguese and Italian, following the video's language, and can be changed or added to: `php artisan vendor:publish --tag=video-demo-lang`.
- **Library pages search engines and link previews understand.** Each public video page has a description, a canonical address, link-preview tags with its poster and video (so a link shared in Slack or WhatsApp shows them), and `VideoObject` structured data. `/help/videos/sitemap.xml` lists every public video with its poster, file and length. Private library pages ask not to be indexed.
- **An MCP server for coding agents.** With `laravel/mcp` installed, Claude Code, Cursor and other agents get the "video-demos" server (`php artisan mcp:start video-demos`): check the setup, list videos, make one (within its spending limit), follow its progress, check which published videos a change broke, publish, and get a video's Pest test.
- **Previews on pull requests.** `php artisan video-demo:preview --changed=<base>` records the published videos a change touches, from their saved plans, into a folder as MP4s and GIFs with a `summary.md` to post on the pull request, saying which still work and where any broke. Nothing is published or changed, and no AI is used. The wiki has a GitHub Actions workflow that runs it on each pull request.
- **Reading a separate frontend's code.** With `VIDEO_DEMO_FRONTEND_PATH` set to the frontend's repository (such as `../web`), the AI reads its router and components under `frontend/` (only the folders in `frontend.code`, `src` by default, with the same deny list and secret hiding), and `--frontend-changed=<ref>` on `verify`, `refresh` and `preview` flags the videos a frontend change touches.
- **`<video-demo-help>` in any frontend:** the public videos that show the page it is on, behind a "Videos for this page" button, from the same `embed.js`. A separate frontend's videos are matched to its pages by their paths. Plays in `<video-demo-player>` count towards the library's watch counts.
- **Every video is also a Pest test.** **Downloads** on a video's page, and `video-demo:export <slug> --format=pest`, turn its plan into a Pest browser test (pestphp/pest-plugin-browser), at no AI cost: the flow the video shows, checked as a test that it still works. Elements are found the way Pest finds them, by label, name or text; a step Pest can't point at the same way gets a comment saying so. The test signs in as the kind of user the video was recorded as (a role from spatie/laravel-permission, a role column or an admin flag).
- **The studio says what to fix before videos can be made.** The studio's videos page and the New video page show a notice when something the doctor checks fails (the AI key, Node, Playwright, ffmpeg…), each with how to fix it, and **Check again**.
- **Published videos in any frontend.** For a Vue, React or other frontend, `<script src="https://your-app.com/help/videos/embed.js" defer></script>` and `<video-demo-player slug="post-a-job"></video-demo-player>` play a public video, in the version for the screen and the page's language, with subtitles. `/help/videos/{slug}.json` gives the same to your own player.
- **`php artisan video-demo:doctor`** checks everything making videos needs, and says how to fix what isn't right: the studio, the AI key, the app's address, a separate frontend and its sign-in, Node, Playwright and its browser, ffmpeg, the queue and a worker, the user to record as, sample data, where published videos go, and the voice. `--json` for scripts; it exits non-zero when something must be fixed.

## v0.18.0 - 2026-10-18

### Added

- **Plans are rehearsed before they are recorded.** Once the pages pass their check, the plan is played on every screen as fast as it goes, without recording, on a copy of the sample database. A step that doesn't work, such as a field the AI named "Title" when the page says "Job title", goes back to the AI before the voice-over is made or anything is recorded. A failed rehearsal counts as one of the video's repairs (`limits.max_repairs`). It costs no AI and takes a few seconds. Videos on your own data aren't rehearsed, as it would make every change twice, and neither are plans recorded again as they were. `VIDEO_DEMO_REHEARSE=false` turns it off.
- **The AI can look at a page while it plans**, when the code doesn't settle a label: a translation key, a component, text from the database, or a separate frontend's page. It opens the page as the video's user and sees its headings, links, buttons and field labels, with secrets hidden. Each look costs about a cent, so it looks only when needed, at most three times per video (`VIDEO_DEMO_PAGE_LOOKS`, 0 turns it off).
- **Apps with a separate frontend.** For a Vue, React or other frontend on its own address that uses the Laravel app as its API, set `VIDEO_DEMO_FRONTEND_URL`. Videos are then recorded on the frontend's pages, while signing in and sample data still go through the Laravel app.
  - **Signing in with any kind of auth:** `VIDEO_DEMO_SIGN_IN` is `session` (the default, also right for Sanctum's cookie-based SPA sign-in), `sanctum-token` or `jwt` (a token made by the app and put in the frontend's `localStorage` under `VIDEO_DEMO_TOKEN_KEY`), or `custom`, with `VideoDemo::signInUsing(fn ($user) => SignIn::header(...)->withCookie(...)->withLocalStorage(...))` for anything else, including Passport and the app's own session after SSO. Sign-in comes from a signed link that only exists while the studio is enabled. On sample data, tokens are made in the video's own database; Sanctum tokens expire after an hour, and the recorder's expired ones are removed.
  - **Sample data across addresses:** the frontend's requests to the app, and through its own address (a dev-server proxy such as Vite's `/api`), carry a signed header the recorder adds, so they reach the video's sample database without any CORS changes.
  - **Page checks for single-page apps:** before any AI is spent, a page is reported when a request it makes to the app fails with a server error, or is refused (401, 403) for the signed-in user, with Laravel's message, or when the frontend sends the browser to its own sign-in page.
  - **A change the app refuses fails the step that sent it:** a POST, PUT, PATCH or DELETE the app answers with a 4xx (a 422 for a field left empty, a 403, a 419) fails that step with Laravel's message, so the AI fixes the plan instead of the video being made showing the error. Reading data (GET), Laravel Precognition's live validation, broadcasting sign-in, and a guest's 401 are left alone. A step that fails for another reason says what the app refused just before, such as "Meanwhile, the app refused a request: POST /api/jobs returned HTTP 419: CSRF token mismatch."
  - Without `VIDEO_DEMO_FRONTEND_URL`, nothing changes.

### Fixed

- **Text boxes found by their label.** A step aimed at an element by its role and label (or placeholder), with no name, such as a text box labelled "Location", used the first element of that role on the page, so typing could go into the wrong field. It now uses the element of that role the label belongs to, and only falls back to the first one when the page has no such label.

## v0.17.12 - 2026-10-17

### Added

- **A Laravel Boost skill.** Apps using Laravel Boost now get a `laravel-video-demos` agent skill when they run `php artisan boost:install` and tick the package. It covers making, fixing, embedding, translating and exporting videos, plan steps, and where published videos are stored. The always-loaded guideline is shorter and points to it. A test keeps both from naming commands, options, methods or styles the package doesn't have.
- **A one-time note asking for a star.** Once a video has been published, the studio shows a small note: if the package saved you time, a star on GitHub helps other Laravel developers find it. **Star on GitHub** or **Not now** hides it for good in that browser, and nothing is tracked. `video-demo:install` ends with the same line.

## v0.17.11 - 2026-10-17

### Added

- **Keep published videos in your codebase.** Set `VIDEO_DEMO_DISK=video-demo-repo` and publishing writes the videos, posters and the list of published videos to `public/video-demo`. Commit that folder and the videos reach production with your next deploy, with no S3 or other shared storage. The studio reminds you to commit and deploy after publishing. Production shows a deployed change at once, rather than when its 5-minute cache runs out.

### Fixed

- **Trying again uses your changes.** On a stopped or failed video, **Try again** and **Record the same plan again** now send the "Make it again" form, so a description, length, style, voice or model changed there is used. Before, they made the video again with its old settings and dropped your changes.
- **Published videos are never left without a way to manage them.** The studio keeps its videos in the app's database and the library keeps the published ones on the storage disk, so when the database was reset, published videos kept playing but disappeared from the studio. Each published video now has its studio record (prompt, plan, style, voice, recordings) saved next to its files. `video-demo:install` brings back any published video the studio doesn't have, and the studio's videos page offers **Bring them back** too, with their files copied back so they play, publish and record again. Videos published before this version come back from what the library lists; plan them again to record them again.

## v0.17.10 - 2026-10-16

### Changed

- **A more natural local voice.** The free local voices now use the full Kokoro model rather than its compressed copy, which sounded harsher and more robotic. The full model is also faster on a computer's processor. It is a one-time download of about 330 MB, made the first time a video uses a local voice or when you run `php artisan video-demo:install-voice` again. Lines spoken by the old model are spoken again the next time their video is recorded.
- **The local voice comes with your videos.** On a teammate's machine or a CI runner, `video-demo:install` now installs the free local voice by itself when a video or style there uses it. Before, you had to know to add `--with-voice`. The wiki's deploy workflow keeps the voice's model between runs.
- **`video-demo:refresh` won't publish a video without its voice.** When a video's voice isn't available where it runs, such as a local voice that isn't installed or a cloud voice without its key, that video fails and the message says how to get the voice. Before, it was recorded again silent and published.
- **Natural voices come first in the voice list:** OpenAI, Gemini and ElevenLabs, then the local voices, and the computer's own robotic voices last. Without a natural voice, the form says how to get one.

### Fixed

- **Highlights stay on what they outline.** In styles with the highlight on, such as Spotlight, the outline now follows its element when the page scrolls or the layout moves (a panel opens, a player changes size), and glides from one element to the next. Before, it stayed where the element first was.

## v0.17.9 - 2026-10-16

### Changed

- **Easier to find.** A plainer description, the words people search for (walkthrough, product tour, screencast, help center, user guide, knowledge base) in the Composer keywords, and the wiki as the package's homepage. The README now opens with when to use the package.

### Fixed

- **What AI assistants are told is up to date.** `llms.txt` and the Laravel Boost guidelines said videos play at 2× by default (it has been real time, 1×, since 0.14) and that `video-demo:install` copies the config (it hasn't since 0.17.5). `llms.txt` also describes how an app's own config copy works now, and what the built-in styles do.

## v0.17.8 - 2026-10-16

### Added

- **Update videos on deploy.** `video-demo:refresh` takes three new options:
  - `--changed=<ref>` records again only the published videos made from code that changed since that commit.
  - `--fix` gives a video whose plan no longer works a new plan from the AI, then records and publishes it.
  - `--json` prints the result for CI. It exits non-zero when a video can't be made.
  - A video whose code isn't known at all is skipped with `--changed`, saying how to record it, so it isn't recorded after every change.

  The wiki's [On deploy](https://github.com/ogunsakin01/laravel-video-demos/wiki/Keeping-Videos-Up-To-Date#on-deploy) section has a GitHub Actions workflow that runs it after each push to `main`, and says what the job needs: the studio's database, the disk production reads, and an AI key for `--fix`. It can also suggest ideas for new videos with `video-demo:suggest`.

### Fixed

- `--changed` (on `verify` and `refresh`) matches a video planned before v0.17.0 on the controllers and route files behind its pages, instead of treating it as changed by every commit. Only a video with no files known at all is always included. Planning it again gives it the full list.
- `video-demo:refresh` no longer records a video that is being made at the same moment, and refuses to run where the studio is disabled (recording signs in through it), as `video-demo:generate` does.

## v0.17.7 - 2026-10-16

### Changed

- **Delete is at the top of a video's page**, first in the row with Unpublish and Publish (or Try again on a failed video), so it can be found without scrolling. It was a link at the bottom of the page.

## v0.17.6 - 2026-10-16

Run `php artisan video-demo:install` after updating.

### Changed

- **Updating the package now updates your settings, even with your own copy of the config.** An app's `config/video-demo.php` used to replace whole sections of the package's config, so new styles, models and devices never reached it. Now your copy is laid over the package's config: every setting in it still wins, and anything it doesn't have comes from the package. Plain lists, such as middleware, are still yours alone. To turn off a built-in style, model or device, set it to `false`, such as `'promo' => false` under `styles`.
- **`video-demo:install` removes a copy of the config that nobody edited.** Older versions of the command made one in every app. It is recognised by matching a version the package shipped exactly, and install says what it removed. A copy you edited stays.

## v0.17.5 - 2026-10-16

Run `php artisan video-demo:install` after updating.

### Changed

- **The built-in styles take the best of Promo reel.**
  - Every built-in style now outlines what is being used. Clean walkthrough, Quick tip and Detailed tutorial gain it, which shows learners exactly where to click.
  - Product showcase and Promo ad gain the camera moving in on each step, and music: the Corporate track for Product showcase, the Upbeat track for Promo ad.
  - Product showcase and Spotlight now write two-line captions: a short title naming what the viewer gains, and a few words under it.
  - `video-demo:install` updates a built-in style in apps that already have it, only when it is still as the package shipped it. A style you changed, in the studio or in your own config, stays as it is.
  - The examples on the Examples page were recorded again to match, and the page now plays them from this release.

### Fixed

- **Promo reel (and Spotlight) were missing in apps that published their config before those styles were added.** The upgrade that adds a new style read it from the app's own `config/video-demo.php`, which didn't list it, and quietly added nothing. New styles now come from the package's own settings when the app's config doesn't have them, and `video-demo:install` adds the ones that were missed. A style the app's config defines still uses the app's settings.
- **`video-demo:install` no longer copies the config into the app.** A copy kept the app on that version's defaults after every upgrade, so new styles and models never reached it. Everything can be set in `.env`; publish the config with `php artisan vendor:publish --tag=video-demo-config` only to change more. When the app already has an older copy, install says which new settings it's missing; delete the copy if you never edited it.
- **The "?" tips on the new video form** are centred, keep to the line of the words they explain, and open upwards when there's no room below, so the one under **Generate video** is no longer cut off by the sidebar. A tip in **More options** is no longer drawn under the field after it, and the command in the cost tip is readable in light and dark mode.

## v0.17.4 - 2026-10-16

### Fixed

- The test of the progress bar on a video's page no longer fails on a slow machine: it stops the clock while it checks. Nothing changes in the package itself.

## v0.17.3 - 2026-10-16

### Fixed

- **AI costs and copies made at the same moment all count.** A video's AI cost is now added in the database, so requests made at once by a job and the studio are all counted against its spending limit. The state of each GIF, square or vertical copy, and the log, are changed with the row locked, so two copies made at once no longer lose each other's state.
- **Pressing a button twice starts the work once.** A copy, a "Does it still work?" check or a "Where to put it" search is no longer started twice by a quick second press.
- **Long translated videos are no longer cut short.** The recording of a translation is now a queued job of its own, with the time its length and versions need, instead of running inside the translation's one-hour job.
- **Every recorder script opens the app the same way.** The recorder scripts share one setup (`resources/recorder/browser.cjs`). "Where to put it" now treats a tablet as a tablet, not a phone. Finding start pages and spots now reports a failed sign-in instead of quietly working signed out. Every script recognises `/signin` and `/sign-in` as sign-in pages, not only `/login`.

### Removed

- The studio's `regenerate` and `rerecord` routes, which nothing used since **Make it again** replaced them. `VideoDemo::rerecord()` stays.

## v0.17.2 - 2026-10-16

### Fixed

- **Secrets are hidden in everything sent to the AI.** The planner's first message (the description, your `video-demo.ai.notes`, page errors on sample data), "Complete with AI", ideas and tool errors went out without the redactor. Both drivers now hide secrets in every message they send.
- `video-demo:clean` deleted a finished video's GIF, square and vertical copies and its "Where to put it" pictures, and the sample database of a video being checked or placed. It now keeps them.
- `video-demo:clean --browser` could remove `storage/` itself when Playwright was installed straight into it. It now only removes a `node_modules` folder's own parent inside storage.
- A logo is stored with the extension of what it is, not what its name says, and an SVG logo is served so no script in it can run.
- The planner no longer sends a request for a video already at its spending limit, and checks the limit before a repair's first request too.
- A tool call from the model without its path is answered with an error instead of failing the video.
- When a recorder script ends without a result, the error now includes what it printed (such as Playwright's own message) instead of only "check that Node and Playwright work". A browser that won't start, or a page scan that fails, is reported as such.
- One page that fails to load no longer ends "Where to put it" for every spot; that position is skipped.
- "Where to put it" sees a translated video's pages in its language.
- The start-page list no longer skips routes that merely begin with "up", such as `/uploads`.
- The studio's video list no longer errors when production can't be reached for requests.
- Deleting a video frees the idea it was made from, so the idea can be made again.
- A copy button pressed twice quickly no longer stays on "Copied".
- The "Other languages" button shows that it's working, like the others.

## v0.17.1 - 2026-10-16

### Changed

- **A shorter video page.** Each section below the video (Where to put it, Does it still work?, Downloads, Watching, Other languages, Plan, Details, Make it again, Progress) now folds to its title and a one-line summary, such as "3 places suggested, all fit" or "Step 10 no longer works". They start folded and remember what each person opened. A section that needs attention stays open: while it's working, when it failed, or when its form has an error. **Where to put it** shows the tag and the publish step once instead of under every place, and each place folds too, with only the first open. The cost card keeps the cost, estimate and length in view, with the rest under **How it was made**, and the embed's HTML is behind **Show the code**.

### Fixed

- The picture of the page where **Does it still work?** found a broken step is framed and sized to the card again.

## v0.17.0 - 2026-10-16

Run `php artisan video-demo:install` after updating, for the new columns and table, and the GIF encoder the recorder now uses.

### Added

- **Find videos a change broke.** `php artisan video-demo:verify` plays each published video's steps in a browser, as fast as they go and without recording, and lists the ones that no longer work: the step, the version and the error. It costs no AI, exits non-zero when a video is broken, and has `--json` for CI. `--changed=origin/main` checks only the videos made from code that differs from that ref: each new plan notes the files the AI read, along with the controllers behind its pages. `--fix` asks the AI for a new plan for each broken video, records it and publishes it again. The studio's **Does it still work?** card does the same for one video, with a picture of the page where it failed and **Fix with a new plan**; broken videos are marked on the videos page. `VideoDemo::verify()` and a `VideoBroke` event do it from code.
- **Edit the plan by hand.** A video's plan is now a form on its page: reword a caption, title card or voice-over line, fix a button name, change a typed value, reorder or remove steps, or add a caption, card or pause. **Save and record it again** records it for free. A new plan from the AI starts from your edits. `VideoDemo::updatePlan()` does the same from code, with the same checks the AI's plans get.
- **Videos for this page.** `<x-video-demo::help />` in your layout shows a "Videos for this page" button on every page a published video shows, with the videos ready to play; pages no video shows render nothing. It matches pages by route, so `/jobs/42` finds videos of `/jobs/7`. `route="jobs.create"` or `route="/jobs/create"` names another page, and `VideoDemo::forPage()` lists them for your own menu. Publish older videos again to list them.
- **The same video in other languages.** **Other languages** on a finished video's page, `video-demo:translate post-a-job fr es` or `VideoDemo::translate()` make it in another language from its plan, without planning again: the AI translates the titles, captions, chapters, voice-over and typed sample text, for about a cent, and the steps are checked with your app in that language, finding again any button whose label the app translates. Each language is a video of its own, linked to the original, with a voice that speaks it. The player plays the version in the page's language (`language="fr"` picks one, `:language="false"` turns it off), and the library lists each video once, in the viewer's language, with a list of its languages.
- **GIF, square and vertical copies.** **Downloads** on a finished video's page makes a GIF (for READMEs, changelogs, emails and chat) and square and vertical (9:16) MP4s for social posts, with the title above the app and the picture following each step. `video-demo:export post-a-job --format=gif --out=docs/post-a-job.gif` and `VideoDemo::export()` do it from the command line and code. Made on your machine with any ffmpeg build, at no AI cost.
- **Logo and end card.** A style can put your logo in a corner of every frame, cards included, and end every video with a title card such as "Try it at example.com". Set them on the Styles page.
- **How videos are watched.** With `VIDEO_DEMO_STATS=true` in production, the player counts plays and how far viewers get, and the library counts searches that found nothing. Only daily totals are kept, with no cookies, IP addresses or user ids. A published video's page in the studio shows its plays, how many watched to the end and where most viewers stop; the videos page lists searches that found nothing. Both are read from production with the requests token. **Ideas** now puts what users asked for and searched for first.
- **A minimisable player.** `<x-video-demo::player slug="…" collapsible />` shows a slim "How-to video" bar that opens into the video and minimises again. It starts minimised (`open` starts it open), takes its colours from your page, works without JavaScript and remembers each viewer's choice.
- **Where to put it checks the layout.** Each suggested spot gets the real collapsible player and is checked, minimised and open, on desktop and phone: no sideways scrolling, nothing beside it squeezed or pushed aside, not clipped or covered. Other positions are tried when one fails. The panel shows pictures of both states on both screens, whether each spot fits, and three steps to add it: the file and exact line, the code around it with the tag pasted in, and publishing.
- **Progress, cost and a worker check.** Finding spots shows a progress bar with its stage and time left. Its AI cost shows with the spots and is counted in the video's cost and the total spent; the videos page now shows small amounts to four decimals instead of $0.00. The videos page, new-video form and a working video's page warn when no queue worker is running, with the command to start one, and the warning clears itself once one starts.

### Changed

- A video recorded again clears an older check that found it broken.
- The recorder opens a video made in another language with the app in that language: it sends the language to the browser and in a cookie the package reads, where the studio is enabled, to set the app's locale.

### Breaking

- Apps that extend `Recorder::run()` need its new return type, `string`. Apps that extend `Encoder::frames()` or `Encoder::join()` need their new last parameter, `?int $fps = null`.
- `Manifest::forArea()` leaves out videos in other languages whose original is listed; `Manifest::find()` still finds them.

## v0.16.0 - 2026-10-15

Run `php artisan video-demo:install` after updating, for the new column.

### Added

- **Where to put it.** A finished video's page can suggest where in your app the video should go. **Suggest where to put it** opens the pages the video visits, as its user and on its sample data, and the AI picks two or three spots people doing the task would see, such as the top of the form it teaches, an empty state or a help panel, for about a cent. Each spot comes with a screenshot of the video in place on a desktop and a phone screen, the view and line to paste it at (when a view shows that section), and the player tag to paste. With no AI key, or once the video has reached its spending limit, it suggests the top of the page the video starts on.

## v0.15.0 - 2026-10-15

Run `php artisan video-demo:install` after updating, for the new columns.

### Added

- **Videos in your app's language.** The titles, captions, title cards, chapters and voice-over are written in the language of your app's pages, read from their `lang` attribute (or `app.locale`) when the pages are checked. Choose another language per video with the new Language field, the `language` option of `VideoDemo::make()` or `video-demo:generate --language=fr`. The voice list shows only the voices that speak it: the cloud voices speak nearly any language, a Mac's own voices cover about 40, and the local Kokoro voices speak English only. A voice that can't speak the video's language is refused on the form, or left out with a note in the log. Voice samples are spoken in the voice's language, and the captions track is marked with it.
- **Promo reel style.** A punchy product promo like the package's own: title cards and captions as big two-line headlines (a bold claim, then a muted line) under a numbered label, words rising in, the app in dark mode with the camera on each step and an outline on what is used, a brisk pace and the upbeat built-in track. Its look is a new **Bold headlines** setting you can give any style. Apps that already have styles get it from the migration, and the studio's Examples page has a sample of it. A style's music that isn't available here, such as a built-in track with those turned off, is now left out instead of refusing the video.

## v0.14.0 - 2026-10-15

Run `php artisan video-demo:install` after updating, for the new column. Videos now play at 1× by default: if you published the config, change `'speed' => (float) env('VIDEO_DEMO_SPEED', 2)` to 1, or set `VIDEO_DEMO_SPEED=1`.

### Added

- **Several videos at once.** `php artisan video-demo:work` runs two queue workers for the package's queue (`--workers=N`, or `VIDEO_DEMO_WORKERS`), restarts any that stop, and stops them all on Ctrl+C. A video handed to two workers at once (the database queue on SQLite can do this) is made once, and is no longer marked failed by the second.
- **Ideas know who each video is for.** Each idea picks the type of user it should be recorded as (admin, member, guest and so on), from your roles and every route's middleware, shows it on the card, and fills it in when you make the video. `video-demo:suggest --json` includes it as `record_as`.

### Changed

- **More natural timing.** Waiting on your app (pages loading, the network settling after a click, something appearing) is cut out of the video, except while the voice-over speaks, so a click goes straight to its result. The cursor moves in a slight arc over a time that suits the distance and rests a moment before clicking, and each click and page change gets a beat to be seen. With the waits cut, videos play at 1× by default, so the cursor and typing move at a person's pace. Captions are no longer stretched to more than 1.4 times their length to reach a target: a video a little short beats one that lingers.
- **Voice-overs that sound less like AI.** The lines are written the way a colleague talks you through a screen: contractions, varied lengths, no stock phrases ("Here's how", "seamless"), each following on from the last, and silence where nothing needs saying. OpenAI and ElevenLabs voices hear the lines around each one, so the narration flows instead of restarting every line, and every line is levelled to the same loudness. The computer's own voices are labelled robotic, for drafts only.
- **New look.** The new-video and Make it again forms are in short sections, with the less-used settings under "More options", explanations behind a "?" instead of under every field, switches, a preview of the style's colours and a live summary of the video. Pages arrive with gentle motion (sections rising in, lists following item by item, line art drawing itself on), and empty states have line art too. Motion is off for people who ask for less.

### Fixed

- The first second or so of every recording was cut off: the start of the video file was measured from before the browser's capture began.
- A local voice keeps its name ("Heart, warm (US)") on a machine where it isn't installed, instead of showing its key.
- When the AI writes no voice-over, the log says why it stopped and what it answered.

### Breaking

- Apps that extend `Encoder::encode()` need its new last parameter, `?Timeline $timeline = null`.

## v0.13.0 - 2026-10-15

Run `php artisan video-demo:install` after updating, for the new column.

### Added

- **View full error.** A failed video's "What went wrong" card has a button that opens the whole error, laid out like Laravel's error page: the exception, its stack trace with the code around each of your app's frames (vendor frames folded), and the video's settings. When a step fails while recording, it also shows a screenshot of the page, the step, Playwright's full message, the browser's console errors and failed requests, and the page structure the recorder saw. On sample data, exceptions your app throws while it is recorded are caught with their stack traces too. **Copy as Markdown** copies it, to paste into an issue or an AI chat.

### Fixed

- A failed step's message no longer ends with two full stops.

## v0.12.1 - 2026-10-15

### Fixed

- Built-in music tracks are downloaded from GitHub when jsDelivr is down, instead of failing.

## v0.12.0 - 2026-10-15

Run `php artisan video-demo:install` after updating, for the new spending table.

### Added

- **A progress bar** on the page of a video being made: how far along it is, as a percentage, and about how long is left, counting down as it goes. It's an estimate from the video's stage, length and versions.

- **Cloud voices.** OpenAI (`gpt-4o-mini-tts`), Gemini (`gemini-3.8-flash-tts`) and ElevenLabs voices join the list when their key is set (`OPENAI_API_KEY`, `GEMINI_API_KEY`, `ELEVENLABS_API_KEY`, or Prism's config). ElevenLabs lists the voices in your account, including your own. They speak in a tone that matches the style's pace. What they cost is added to the video's cost and counts toward its spending limit; a voice that would go over is left out, and the log says so. Prices are in `voice.providers.*.price_per_1k_chars`.
- **Built-in music.** Four tracks composed for the package, free to use in any video (CC0): Calm, Upbeat, Corporate and Cinematic. Each is downloaded the first time a video uses it and kept; they aren't in the Composer download. `VIDEO_DEMO_BUILT_IN_MUSIC=false` hides them.

### Changed

- **The total spent on AI** now counts every AI request as it happens: planning, Improve with AI, writing the voice-over's lines, cloud voices, Complete with AI and suggesting ideas. It stays the same when a video is deleted, and the videos page keeps it, and each video's status, cost and length, up to date without reloading. What was spent before updating is carried over.

## v0.11.0 - 2026-10-15

Run `php artisan video-demo:install` after updating, for the new columns.

### Added

- **Voice-over.** A narrator speaks a short line at each step, written by the AI in the style's tone. Choose a voice per video, on the form or in Make it again, with **Play sample**; a style can set a default. Voices are only offered when they work on your machine:
  - **Local voices** (Kokoro): ten natural English voices, free, on your machine. Install them with `php artisan video-demo:install-voice` or `video-demo:install --with-voice` (about 150 MB next to the recorder, kept out of git, and a 100 MB model in your user cache folder, shared by every project). `video-demo:clean --voice` removes them.
  - **This computer's voice**: macOS `say`, Windows speech or `espeak-ng`, for drafts, with nothing to install.
  Each step waits until its line is finished. Clips are saved, so recording the same plan again doesn't speak them again; adding a voice to a video that has a plan writes the lines with one small request instead of planning again. If a voice fails, the video is still made, silent, and its log says why.
- **Background music.** Upload your own tracks in the new Music section of the Styles page (or list them in `video-demo.music.tracks`), and choose one per video with a volume slider, or as a style's default. Music loops to the video's length, fades in and out, and drops while the voice speaks. It's mixed by the package itself, so any ffmpeg build works.
- **Subtitles** from the spoken lines, published with each version; the player and the studio preview get a CC button.
- `--voice` and `--music` on `video-demo:generate`, `voice`, `music` and `music_volume` options in `VideoDemo::make()`, and `VideoDemo::voices()` and `VideoDemo::music()`. `VideoDemo::url($entry, 'subtitles')` gives a version's subtitles, and `url()` now returns null for a file a video doesn't have.

### Changed

- Apps that extend `Recorder::record()` need the new last parameter, `array $voice = []`.

## v0.10.2 - 2026-10-15

### Fixed

- `video-demo:install` keeps the recorder's files out of your app's git repository. They live in `storage/video-demo/`, which Laravel doesn't ignore (it only ignores the storage folders it makes itself), so a `git add .` could commit about 20 MB of Node packages and deploy them. The install now adds `storage/video-demo/.gitignore`, the way Laravel does for its own folders. Run `php artisan video-demo:install` once to add it to an existing app.

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
