## Laravel Video Demos (ogunsakin01/laravel-video-demos)

This app makes recorded how-to videos of itself. An AI model reads the app's code and plans a walkthrough; Playwright records it against the local app; videos are published to a storage disk and shown in a help library and in pages. For making, fixing, embedding, translating or exporting videos, use the `laravel-video-demos` skill.

- Videos are made only where the studio is enabled (`APP_ENV=local` or `VIDEO_DEMO_STUDIO=true`). Never point the recorder at production.
- Planning a video spends the app owner's AI credit, within each video's spending limit (`--max-cost`, $0.50 by default). Recording an existing plan again costs nothing. Say what a command will cost before running one that plans.
- Prefer the commands with `--json`: `video-demo:generate`, `list`, `publish`, `verify`, `refresh`, `translate`, `export`, `suggest`.
- Queued videos run on the `video-demo` queue connection (`php artisan video-demo:work`). After updating the package, run `php artisan video-demo:install` and restart the workers.
- After changing views, routes or labels, run `php artisan video-demo:verify --changed=origin/main --json` to find published videos the change broke.
- Embed with `<x-video-demo::player slug="…" />` and, in a layout, `<x-video-demo::help />`. Both render nothing until the video is published.
- Production reads videos from `VIDEO_DEMO_DISK`, so the machine that publishes must use the same disk: S3 or R2, or `video-demo-repo` (`public/video-demo`, committed with the code).
- `APP_URL` must be the address the app opens at. Outside local, the studio needs a `viewVideoDemoStudio` gate.
