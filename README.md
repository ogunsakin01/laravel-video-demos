# Laravel Video Demos

[![Latest version on Packagist](https://img.shields.io/packagist/v/ogunsakin01/laravel-video-demos.svg?style=flat-square)](https://packagist.org/packages/ogunsakin01/laravel-video-demos)
[![Tests](https://img.shields.io/github/actions/workflow/status/ogunsakin01/laravel-video-demos/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ogunsakin01/laravel-video-demos/actions/workflows/tests.yml)
[![Total downloads](https://img.shields.io/packagist/dt/ogunsakin01/laravel-video-demos.svg?style=flat-square)](https://packagist.org/packages/ogunsakin01/laravel-video-demos)
[![PHP 8.3+](https://img.shields.io/badge/php-8.3%2B-777bb4.svg?style=flat-square)](composer.json)
[![Laravel 12 and 13](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20.svg?style=flat-square)](composer.json)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE.md)

Describe a flow in your Laravel app and get a recorded how-to video of it. Claude reads your app's code and plans the walkthrough. Playwright then records it in a real browser against your local copy of the app, with a cursor, captions and title cards. You choose the length, the style and the screens (desktop, TV, tablet, mobile), and you see the cost before anything is spent.

Videos are made on your machine and published to any Laravel disk. Your production app shows them in a ready-made help library, or inside your own pages with a Blade component.

## See it

These tours were recorded by the package itself, from the demo app in [`workbench/`](workbench). The previews below play at 2.5× speed; download the full videos for the normal pace.

### The studio, where you make videos

[![The studio, at 2.5× speed](docs/studio-tour.gif)](docs/studio-tour.mp4)

[Full studio tour (MP4, 1½ minutes)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/studio-tour.mp4)

### The library, where your users watch them

| Desktop | Mobile |
| --- | --- |
| [![The library on desktop, at 2.5× speed](docs/library-tour.gif)](docs/library-tour.mp4) | [![The library on mobile, at 2.5× speed](docs/library-tour-mobile.gif)](docs/library-tour-mobile.mp4) |

[Full library tour (MP4)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/library-tour.mp4) · [Mobile version (MP4)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/library-tour-mobile.mp4)

The videos in the demo library all play one sample clip, recorded from the demo app's "Post a job" page. The demo app answers "Complete with AI" with a fixed reply, so the tours could be recorded without an API key.

## How it works

1. **You describe the video** in the studio: a few words are enough, and **Complete with AI** turns them into a full description with a title, start page and length. Pick a length, a style and the screens to record.
2. **Claude reads your code.** The planner gives Claude your route list and read-only access to the folders you allow (by default `app`, `routes`, `resources/views`, `resources/js`, `lang`), never `.env` or anything that looks like a secret. Claude writes a step-by-step plan: go to a page, click a button, type into a field, show a caption.
3. **Playwright records the plan** against your local app, once per screen you chose. A step can be limited to some screens, such as opening the menu on mobile.
4. **If a step fails,** Claude gets the error and the page's accessibility tree, fixes the plan, and the recorder tries again.
5. **ffmpeg encodes** each recording to MP4 with a poster image.
6. **You watch it and publish it.** The video and its versions are copied to your storage disk and listed in a manifest file that your production app reads.

The plan is stored with the video. After your app's look changes, `php artisan video-demo:refresh` records every published video again from its plan, at no Claude cost.

## Requirements

- PHP 8.3+ and Laravel 12 or 13
- Node.js 18+ and ffmpeg on the machine where you make videos (not in production)
- An [Anthropic API key](https://console.anthropic.com/)

## Installation

```bash
composer require ogunsakin01/laravel-video-demos
php artisan video-demo:install   # publishes the config, installs Playwright and Chromium
php artisan migrate
```

Add to `.env` on your machine:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
# The user the recorder signs in as, by id or email
VIDEO_DEMO_LOGIN_AS=demo@example.com
# Where your local app is served, if not APP_URL
VIDEO_DEMO_BASE_URL=https://myapp.test
```

Start a queue worker and open the studio at `/video-demo/studio`:

```bash
php artisan queue:work --timeout=1800
```

## The studio

The studio is where videos are made. It is only registered when `APP_ENV` is `local` (or `VIDEO_DEMO_STUDIO=true`), and never in production unless you set `studio.allow_in_production`. Its routes, including the signed sign-in link the recorder uses, do not exist when it is off.

- **New video**: describe the flow, or use **Complete with AI**. Choose the length (a target: captions and pauses stretch or shrink to fit), the versions to record, a style, the model and the effort. The estimated cost updates as you change them.
- **Video page**: watch each version, read the plan, see the actual cost and progress, publish or unpublish, record the same plan again (free), or ask Claude for a new plan.
- **Styles**: how Claude writes each video (its "direction") and how cards and captions look (light or dark, accent colour, captions at the top or bottom, pace). The defaults come from `config/video-demo.php` and are copied into the database the first time; add and edit your own on this page.
- **Requested by users**: searches in your library that found nothing. Click one to start a video.

## The library

Your users watch videos at `/help/videos` (public) and `/admin/videos` (private videos too, behind `auth`). Both prefixes, their middleware and their domains are in the config. The library reads the published manifest, so production needs only the storage disk, not the studio's database.

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

To restyle the library pages, publish the views:

```bash
php artisan vendor:publish --tag=video-demo-views
```

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

Claude writes one plan for all of them. Each extra version only adds recording time on your machine. Sizes and defaults are in the `devices` and `default_devices` config.

If your app shows videos, set `VIDEO_DEMO_BROWSER_CHANNEL=chrome` to record with installed Chrome: Playwright's own Chromium can't play MP4.

## What it costs

Recording and encoding run on your machine and cost nothing. Each app owner pays Anthropic for their own use, with their own key.

The studio shows an estimate before you generate, and each video records what it actually cost, from the token counts the API returns. Estimates from the package's estimator, for an app with about 80 routes:

| Model, effort | 30 seconds | 1 minute | 2 minutes |
| --- | --- | --- | --- |
| Claude Opus 5.5, high | ~$0.54 | ~$0.63 | ~$0.90 |
| Claude Opus 5.5, medium | ~$0.38 | ~$0.45 | ~$0.66 |
| Claude Sonnet 5.5, high | ~$0.28 | ~$0.33 | ~$0.47 |
| Claude Haiku 5.5, high | ~$0.01 | ~$0.02 | ~$0.02 |

These are estimates, not measurements yet: compare them with the actual costs your videos show. "Complete with AI" costs about a cent. Generation stops if a video passes `limits.max_cost_per_video` ($2 by default). Prices are in the config; update them if Anthropic's change.

## Configuration

Everything is in [`config/video-demo.php`](config/video-demo.php), with comments. The settings you are most likely to change:

| Setting | What it does |
| --- | --- |
| `ai.model`, `ai.effort` | Defaults for new videos |
| `ai.code.paths`, `ai.code.deny` | What Claude may read |
| `ai.notes` | Anything Claude should know that the code doesn't say, such as your sample data |
| `recorder.login_as` | The user the recorder signs in as |
| `styles` | The styles offered at first |
| `devices`, `default_devices` | Screen sizes, and which are switched on |
| `limits` | Cost, request and repair limits per video |
| `ui.accent`, `ui.logo`, `ui.font_url` | The look of the studio and library |

## Security

- The studio and its sign-in link only exist on machines where it is enabled.
- Claude can only read the folders in `ai.code.paths`, and never files matching `ai.code.deny`. Paths outside them, including `../` tricks, are refused.
- The recorder drives your local app with your sample data. It can click anything a user can, so record against a local database, not production.
- Your API key stays in your local `.env` and is never needed in production.

Please report security problems privately; see [SECURITY.md](SECURITY.md).

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
