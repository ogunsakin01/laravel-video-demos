# Laravel Video Demos

[![Latest version on Packagist](https://img.shields.io/packagist/v/ogunsakin01/laravel-video-demos.svg?style=flat-square)](https://packagist.org/packages/ogunsakin01/laravel-video-demos)
[![Tests](https://img.shields.io/github/actions/workflow/status/ogunsakin01/laravel-video-demos/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ogunsakin01/laravel-video-demos/actions/workflows/tests.yml)
[![Total downloads](https://img.shields.io/packagist/dt/ogunsakin01/laravel-video-demos.svg?style=flat-square)](https://packagist.org/packages/ogunsakin01/laravel-video-demos)
[![PHP 8.3+](https://img.shields.io/badge/php-8.3%2B-777bb4.svg?style=flat-square)](composer.json)
[![Laravel 12 and 13](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20.svg?style=flat-square)](composer.json)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE.md)

**Use it when a Laravel app needs how-to videos, a help center, onboarding or product demo videos, kept up to date as the app changes.**

Describe a flow in your Laravel app and get a recorded how-to video of it. An AI model (Claude by default, or OpenAI, Gemini, a local model and others) reads your app's code and plans the walkthrough. Playwright then records it in a real browser against your local copy of the app, with a cursor, captions and title cards, and cuts out the waits on your app. It plays at real time, or up to 3× faster if you choose. You choose the length, the style (from step-by-step tutorials to short ads with animated line art) and the screens (desktop, TV, tablet, mobile), and you see the cost before anything is spent.

Videos are made on your machine and published to any Laravel disk. Your production app shows them in a ready-made help library, or inside your own pages with a Blade component.

**It works with both kinds of Laravel app:**

| Your app | What to do |
| --- | --- |
| **A full-stack Laravel app**: Blade, Livewire, Inertia, or Vue or React inside the same project | Install it and make videos. Nothing else to set up. |
| **A Laravel API with a separate frontend**: a Vue, React or other app in its own repository, on its own address | Install it in the Laravel API and set `VIDEO_DEMO_FRONTEND_URL`. Videos are recorded on the frontend, signed in with a session, Sanctum token, JWT, or your own way (SSO included). See [Separate Frontend](https://github.com/ogunsakin01/laravel-video-demos/wiki/Separate-Frontend). |

Either way the package goes in the Laravel app, the AI reads its code, and videos are recorded on [sample data](https://github.com/ogunsakin01/laravel-video-demos/wiki/Sample-Data), so your own data isn't touched.

```bash
composer require ogunsakin01/laravel-video-demos
php artisan video-demo:install
```

Run `php artisan video-demo:doctor` to check everything is set up, then follow the [setup checklist](https://github.com/ogunsakin01/laravel-video-demos/wiki/Setup-Checklist). Everything else is in the **[wiki](https://github.com/ogunsakin01/laravel-video-demos/wiki)**.

## See it

GitHub doesn't play videos stored in a repository, so the previews below are GIFs; the links under them are the full MP4s.

### What it does

[![Laravel Video Demos in dark mode: describe a flow, and the AI plans and records it](docs/promo.gif)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/social/laravel-video-demos-promo.mp4)

[Full video (MP4, about three minutes)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/social/laravel-video-demos-promo.mp4)

### The studio, where you make them

[![The studio: describe a video, choose its screens, style and spending limit, then review and publish it](docs/studio-tour.gif)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/studio-tour.mp4)

[Full studio tour (MP4)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/studio-tour.mp4)

### The library, where your users watch them

| Desktop | Mobile |
| --- | --- |
| [![The library on desktop](docs/library-tour.gif)](docs/library-tour.mp4) | [![The library on mobile](docs/library-tour-mobile.gif)](docs/library-tour-mobile.mp4) |

[Full library tour (MP4)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/library-tour.mp4) · [Mobile version (MP4)](https://raw.githubusercontent.com/ogunsakin01/laravel-video-demos/main/docs/library-tour-mobile.mp4)

The studio and library tours were recorded by the package itself, from the demo app in [`workbench/`](workbench), with the highlight on. The videos in the demo library all play one sample clip, recorded from the demo app's "Post a job" page. The demo app answers "Complete with AI" with a fixed reply, so the tours could be recorded without an API key.

## Quick start

```bash
composer require ogunsakin01/laravel-video-demos
php artisan video-demo:install          # migrations, the recorder's browser, the sample database
```

Add your AI key to `.env`, and make sure `APP_URL` is the address you open the app at:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...            # or OpenAI, Gemini, a local model…
APP_URL=https://myapp.test
```

Start the package's workers, then open `/video-demo/studio`:

```bash
php artisan video-demo:work          # makes 2 videos at once; --workers=4 for more
```

The [setup checklist](https://github.com/ogunsakin01/laravel-video-demos/wiki/Setup-Checklist) walks through each step and how to check it. To update later, run `php artisan video-demo:install` again; see [Upgrading](https://github.com/ogunsakin01/laravel-video-demos/wiki/Upgrading).

## How it works

1. **You describe the video** in the studio. A few words are enough: **Complete with AI** fills in the rest.
2. **A sample database is prepared**, so your own data is never used. The AI reads your routes' middleware, policies and gates, and adds what each page needs, such as a permission or a profile photo.
3. **The AI reads your code** (only the folders you allow, with secrets hidden) and writes a step-by-step plan. When the code doesn't settle a label, such as one from a translation or a component, it looks at the page.
4. **Pages are checked** and, on sample data, **the plan is rehearsed** in a browser, without recording, at no AI cost. A step that doesn't work goes back to the AI before anything is recorded.
5. **Playwright records the plan** on each screen you chose. If a step still fails, the AI fixes the plan and it tries again.
6. **You watch it and publish it** to your storage disk, where your production app's help library reads it.

The plan is stored with the video, so after a redesign you can record it again at no AI cost.

## What you get

- **[A studio](https://github.com/ogunsakin01/laravel-video-demos/wiki/The-Studio)** to describe, estimate, stop, review, publish and make videos again. **Ideas** suggests the videos worth making first.
- **[Sample data](https://github.com/ogunsakin01/laravel-video-demos/wiki/Sample-Data)**: recorded as any type of user (Admin, Member…) on a database made for the video, never your own.
- **[Voice-over and music](https://github.com/ogunsakin01/laravel-video-demos/wiki/Voice-Over-and-Music)**: a narrator that speaks a line at each step, with free local voices or OpenAI, Gemini and ElevenLabs ones, and background music (four built-in tracks, or your own) that steps aside while the voice speaks. Subtitles come with it. Off unless you choose them.
- **Where to put it**: for a finished video, two or three spots in your app where it would help, each checked not to upset the layout on desktop and phone, with pictures of the video in place and the exact line of code to paste a collapsible player at.
- **[Up to date](https://github.com/ogunsakin01/laravel-video-demos/wiki/Keeping-Videos-Up-To-Date)**: `video-demo:verify` finds the videos a change broke, in CI too, at no AI cost, and fixes them with `--fix`. [On each pull request](https://github.com/ogunsakin01/laravel-video-demos/wiki/Keeping-Videos-Up-To-Date#on-pull-requests), `video-demo:preview` records the videos it touches, for reviewers to watch. [On each deploy](https://github.com/ogunsakin01/laravel-video-demos/wiki/Keeping-Videos-Up-To-Date#on-deploy), `video-demo:refresh --changed --fix` records again just the videos the change touched. Edit a plan by hand to reword a caption or fix a button name, and record it again for free.
- **Your app's language, and others**: titles, captions and the voice-over are written in the language of your app's pages, or one you choose per video. Make the same video in more languages from its plan, for about a cent each; viewers see their own.
- **Downloads**: a GIF for READMEs and emails, square and vertical copies for social posts, and the video's steps as a Pest browser test, so every help video is also a test that the flow still works.
- **[Styles and screens](https://github.com/ogunsakin01/laravel-video-demos/wiki/Styles-and-Screens)**: from step-by-step tutorials to the Spotlight feature reel and a Promo reel with bold headlines, on desktop, TV, tablet and mobile, at the speed you choose, with your logo and an end card if you like.
- **[A help library](https://github.com/ogunsakin01/laravel-video-demos/wiki/The-Library)** for your users, with a written step-by-step guide under each video and pages search engines can index, a Blade tag to put any video on any page, and `<x-video-demo::help />` to show each page's own videos. Optional counts show how far viewers get and what they searched for in vain.
- **[A separate frontend](https://github.com/ogunsakin01/laravel-video-demos/wiki/Separate-Frontend)**: a Vue, React or other app on its own address that uses your Laravel app as its API, signed in with a session, Sanctum token, JWT or your own way (SSO included), on sample data, and played inside it with `<video-demo-player>`.
- **[Cost control](https://github.com/ogunsakin01/laravel-video-demos/wiki/AI-Providers-and-Costs)**: an estimate first, a spending limit on every video ($0.50 unless you change it), and the real cost afterwards.
- **[Any model](https://github.com/ogunsakin01/laravel-video-demos/wiki/AI-Providers-and-Costs#providers)**: Claude by default, or OpenAI, Gemini, Mistral, a local model with Ollama, and others through Prism.
- **[Commands](https://github.com/ogunsakin01/laravel-video-demos/wiki/Command-Line)** with JSON output for scripts and AI agents, [an MCP server](https://github.com/ogunsakin01/laravel-video-demos/wiki/Command-Line#for-ai-agents) for coding agents (Claude Code, Cursor), and **[a facade](https://github.com/ogunsakin01/laravel-video-demos/wiki/Using-It-From-Code)** to build your own screens.

## Documentation

| | |
| --- | --- |
| Getting started | [Setup checklist](https://github.com/ogunsakin01/laravel-video-demos/wiki/Setup-Checklist) · [Installation](https://github.com/ogunsakin01/laravel-video-demos/wiki/Installation) · [Upgrading](https://github.com/ogunsakin01/laravel-video-demos/wiki/Upgrading) |
| Making videos | [The studio](https://github.com/ogunsakin01/laravel-video-demos/wiki/The-Studio) · [Sample data](https://github.com/ogunsakin01/laravel-video-demos/wiki/Sample-Data) · [Styles and screens](https://github.com/ogunsakin01/laravel-video-demos/wiki/Styles-and-Screens) · [Voice-over and music](https://github.com/ogunsakin01/laravel-video-demos/wiki/Voice-Over-and-Music) · [Command line](https://github.com/ogunsakin01/laravel-video-demos/wiki/Command-Line) |
| Showing videos | [The library](https://github.com/ogunsakin01/laravel-video-demos/wiki/The-Library) |
| Going further | [Using it from code](https://github.com/ogunsakin01/laravel-video-demos/wiki/Using-It-From-Code) · [AI providers and costs](https://github.com/ogunsakin01/laravel-video-demos/wiki/AI-Providers-and-Costs) · [Configuration](https://github.com/ogunsakin01/laravel-video-demos/wiki/Configuration) |
| Help | [Troubleshooting](https://github.com/ogunsakin01/laravel-video-demos/wiki/Troubleshooting) · [FAQ](https://github.com/ogunsakin01/laravel-video-demos/wiki/FAQ) · [Security](https://github.com/ogunsakin01/laravel-video-demos/wiki/Security) |

## What it costs

Recording and encoding run on your machine and cost nothing. You pay your AI provider for planning, with your own key: a 30-second video on the default model, Claude Sonnet 5.5, costs about $0.20 to $0.30. Recording the same plan again is free. See [AI providers and costs](https://github.com/ogunsakin01/laravel-video-demos/wiki/AI-Providers-and-Costs).

## Requirements

- PHP 8.3+ and Laravel 12 or 13
- Node.js 18+ and ffmpeg on the machine where you make videos, not in production ([another ffmpeg](https://github.com/ogunsakin01/laravel-video-demos/wiki/Installation#ffmpeg-that-isnt-on-your-path) works too)
- An [Anthropic API key](https://console.anthropic.com/), or another provider's

## Security

The studio only runs on your machine (or behind a gate you define). Only the folders you allow are sent to your AI provider, never `.env`, with anything that looks like a secret hidden first. Videos are recorded on sample data, not your own. See [Security](https://github.com/ogunsakin01/laravel-video-demos/wiki/Security), and please report problems privately as described in [SECURITY.md](SECURITY.md).

## For AI agents

- **[Laravel Boost](https://laravel.com/docs/boost):** the package ships Boost guidelines; run `php artisan boost:install` and choose it.
- **Commands with JSON output:** `video-demo:generate … --json`. See [Command line](https://github.com/ogunsakin01/laravel-video-demos/wiki/Command-Line).
- **[llms.txt](llms.txt)** sums up the package for AI models, and **[AGENTS.md](AGENTS.md)** is for agents working on the package itself.

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
