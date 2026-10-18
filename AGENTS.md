# Working on this package

Instructions for AI coding agents (and people) changing laravel-video-demos itself. To use the package in an app, see [README.md](README.md) and [llms.txt](llms.txt).

## Layout

- `src/Ai`: the planner and its tools. `Drivers/` talks to providers: `AnthropicDriver` (Anthropic SDK, the default) and `PrismDriver` (everything else). `CodeTools` is what the model may read, `PageTools` lets it look at a page while planning, `SampleDataTools` adds sample data; `Redactor` hides secrets, and both drivers run it on every message they send (`RedactsSecrets`).
- `src/Recording`: `Plan` (the step list, its JSON schema and the checks every plan passes, from the AI or edited by hand), `Recorder` (runs `resources/recorder/record.cjs` with Node), `Verifier` (plays a plan without recording, for `video-demo:verify`; `GenerateVideo` rehearses plans the same way before recording), `SignIn` (what a separate frontend's browser needs to be signed in: presets and `VideoDemo::signInUsing()`), `PestTest` (a plan as a Pest browser test), `Preview` (a plan recorded into a folder for `video-demo:preview`), `Exporter` (GIF, square and vertical copies), `Encoder` (ffmpeg, without filters, so any build works), `Devices`.
- `resources/recorder`: the Playwright recorder. `browser.cjs` is how every script opens the app (address, screen, sample data cookie or header, signing in, a separate frontend's address and API), so they all do it the same way, and describes a page for the AI (`snapshot`). `helpers.cjs` holds the cursor and the title-card and caption overlay, which runs inside the recorded page. `camera.cjs` draws the frames of styles with zoom through a virtual camera (`src/Recording/Camera.php` plans it); `frame.cjs` draws square and vertical copies and `gif.cjs` makes GIFs (with gifenc), all in the browser; `check.cjs`, `pages.cjs` and `look.cjs` (a page for the AI while it plans) open pages without recording.
- `src/Library`: `Manifest` (the published videos, on the storage disk), `Publisher`, `Restorer` (brings back published videos the studio's database lost), `Stats` (watch counts) and `Guide` (the written guide under each library video, made from its plan; its words are in `resources/lang`).
- `src/Mcp`: the "video-demos" MCP server and its tools, registered when the app has laravel/mcp.
- `src/Support/Doctor.php`: the setup checks behind `video-demo:doctor` and the studio's setup notice.
- `resources/embed/video-demo-player.js`: the `<video-demo-player>` web component, served by the library at `embed.js`.
- `src/VideoDemo.php` and `src/Facades/VideoDemo.php`: the public API for apps that build their own screens.
- `src/Http`, `routes`, `resources/views`: the studio (local only, behind `AuthorizeStudio`) and the library (production).
- `workbench/`: a demo app (Testbench) with sample data, and the tour plans that record the README videos.

## Checks

```bash
composer check   # Pint, PHPStan (level 5), PHPUnit
```

Recording tests run only with Playwright available: `VIDEO_DEMO_TEST_NODE_MODULES=/path/to/node_modules composer test`. CI runs everything on PHP 8.3 and 8.4 with Laravel 12 and 13.

## Rules

- Never call a real AI API in tests. Use `tests/Concerns/FakesAnthropic.php` for Claude and `Prism::fake()` for other providers.
- Fix what PHPStan reports; don't ignore or baseline it.
- The planner's conversation is append-only (it keeps the prompt cache and thinking blocks valid). Never edit or remove earlier messages.
- Anything sent to a model passes through `Redactor`.
- UI: Geist, the warm paper palette in `resources/views/partials/styles.blade.php`, light and dark mode, phone width. Solid colours only: no gradients, anywhere, including the recorder's overlay.
- Plain, specific wording in UI text and docs.
- Add a line under "Unreleased" in `CHANGELOG.md` for user-facing changes.
- After changing `config/video-demo.php`, add its new fingerprint to `PackageConfig::RELEASED` (a test says which), so `video-demo:install` can still tell an unedited copy of it.
- Commits have no co-author or AI attribution trailers.
