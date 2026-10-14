# Changelog

All notable changes to this package are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [semantic versioning](https://semver.org/). While the version is 0.x, minor releases may include breaking changes; they will be listed here.

## Unreleased

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
