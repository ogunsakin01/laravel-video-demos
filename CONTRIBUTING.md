# Contributing

Thanks for helping. Bug reports, ideas, docs fixes and pull requests are all welcome.

## Before you start

- **Bugs:** open an issue with the steps to reproduce, what you expected, and your PHP, Laravel and package versions. The bug report form asks for these.
- **New features:** open an issue first, so we can agree on the approach before you spend time on it.
- **Security problems:** don't open an issue. See [SECURITY.md](SECURITY.md).

## Setting up

```bash
git clone https://github.com/ogunsakin01/laravel-video-demos.git
cd laravel-video-demos
composer install
composer test
```

### Recording tests

Three tests record real videos and are skipped unless Playwright is available. To run them you need Node.js and ffmpeg:

```bash
mkdir -p /tmp/recorder && cd /tmp/recorder
npm init -y && npm install playwright@1.64.0 && npx playwright install chromium
cd -
VIDEO_DEMO_TEST_NODE_MODULES=/tmp/recorder/node_modules composer test
```

### The demo app

`workbench/` holds a small demo app with sample videos, styles and requests:

```bash
composer serve
```

Then open http://127.0.0.1:8123/video-demo/studio. The demo app answers "Complete with AI" with a fixed reply unless `ANTHROPIC_API_KEY` is set, so it costs nothing to try.

## Tests and Claude

The planner is tested with the real Anthropic SDK against a local fake of the Messages API (`tests/Concerns/FakesAnthropic.php`), so tests never spend money. Please test new Claude features the same way, and never add tests that call the real API.

## Pull requests

- One change per pull request, with tests.
- Keep to the style of the surrounding code: plain names, short comments that explain why.
- UI changes: include before and after screenshots, check light and dark mode and phone width, and use solid colours (no gradients).
- Add a line under "Unreleased" in [CHANGELOG.md](CHANGELOG.md).
- Make sure `composer test` passes.

By contributing, you agree that your contributions are licensed under the [MIT license](LICENSE.md).
