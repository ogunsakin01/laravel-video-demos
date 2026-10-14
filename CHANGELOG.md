# Changelog

All notable changes to this package are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [semantic versioning](https://semver.org/). While the version is 0.x, minor releases may include breaking changes; they will be listed here.

## Unreleased

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
