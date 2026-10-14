<?php

use Ogunsakin01\VideoDemo\Studio\Examples;

return [

    /*
    |--------------------------------------------------------------------------
    | Studio
    |--------------------------------------------------------------------------
    |
    | The screens where developers create, preview and publish videos. Claude
    | reads the app's code and the recorder drives the app in a browser, so
    | the studio is meant to run on a developer's machine only. Its routes
    | are not registered at all when it is disabled, and never in production
    | unless "allow_in_production" is set.
    |
    */

    'studio' => [
        'enabled' => env('VIDEO_DEMO_STUDIO', env('APP_ENV') === 'local'),
        'allow_in_production' => false,
        // Outside local, only users this gate allows may use the studio. With
        // no such gate defined, the studio is closed outside local.
        'gate' => 'viewVideoDemoStudio',
        'prefix' => 'video-demo/studio',
        'middleware' => ['web'],
        'domain' => null,

        // Your production address, so the studio can show what users asked
        // for there. Needs video-demo.library.requests_token on both sides.
        'production_url' => env('VIDEO_DEMO_PRODUCTION_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Library
    |--------------------------------------------------------------------------
    |
    | The pages your app's users see. The public library lists videos marked
    | public. The private library also lists private videos and the videos
    | users have asked for. Both read the published manifest, so they work
    | in production without the studio's database rows.
    |
    */

    'library' => [
        'public' => [
            'enabled' => true,
            'prefix' => 'help/videos',
            'middleware' => ['web'],
            'domain' => null,
        ],
        'private' => [
            'enabled' => true,
            'prefix' => 'admin/videos',
            'middleware' => ['web', 'auth'],
            'domain' => null,
        ],

        // Let visitors ask for a video when a search finds nothing
        'accept_requests' => true,

        // Lets the studio on your machine fetch those requests from production.
        // Send it as a bearer token; leave empty to turn the endpoint off.
        'requests_token' => env('VIDEO_DEMO_REQUESTS_TOKEN'),

        // How long production caches the manifest, in seconds
        'cache_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Published videos, posters and the manifest go to this disk. For videos
    | made on your machine to appear in production, use a disk both can
    | reach, such as S3 or R2. Studio working files stay on "work_disk".
    |
    */

    'storage' => [
        'disk' => env('VIDEO_DEMO_DISK', 'public'),
        'path' => 'video-demo',
        'work_disk' => 'local',
        'work_path' => 'video-demo/work',
    ],

    /*
    |--------------------------------------------------------------------------
    | Claude
    |--------------------------------------------------------------------------
    |
    | Each app owner uses their own API key. Prices are US dollars per million
    | tokens and are used for the cost preview and for each video's cost.
    | Check https://claude.com/pricing and update them when prices change.
    |
    */

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('VIDEO_DEMO_MODEL', 'claude-opus-5-5'),
        // How hard Claude thinks (low to max). Other providers ignore it.
        'effort' => env('VIDEO_DEMO_EFFORT', 'high'),

        // Seconds to wait for one reply from the model
        'timeout' => 600,

        // When Claude Opus or Sonnet declines a request, let the API retry it
        // on another model instead of failing (Anthropic only)
        'fallbacks' => true,

        // Each model's provider and prices (US dollars per million tokens).
        // "anthropic" models use the Anthropic SDK. Any other provider goes
        // through Prism (composer require prism-php/prism), with its key in
        // config/prism.php. Leave prices out and the cost shows as unknown.
        'models' => [
            'claude-opus-5-5' => ['label' => 'Claude Opus 5.5', 'provider' => 'anthropic', 'input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20],
            'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5', 'provider' => 'anthropic', 'input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20],
            // Haiku's rates for prompts of up to 100K tokens; longer prompts cost 5 times as much
            'claude-haiku-5-5' => ['label' => 'Claude Haiku 5.5', 'provider' => 'anthropic', 'input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01],

            // Other providers, through Prism. Use the model names your provider
            // lists, and copy prices from its pricing page:
            // 'your-openai-model' => ['label' => 'OpenAI model', 'provider' => 'openai', 'input' => null, 'output' => null],
            // 'your-gemini-model' => ['label' => 'Gemini model', 'provider' => 'gemini', 'input' => null, 'output' => null],
            // 'qwen3-coder' => ['label' => 'Qwen3 Coder (local)', 'provider' => 'ollama', 'input' => 0, 'output' => 0],
        ],

        // Writing to the 5-minute prompt cache costs this many times the input price
        'cache_write_multiplier' => 1.25,

        // Folders Claude may read, relative to the app's root. Files matching
        // "deny" are never read, wherever they are.
        'code' => [
            'paths' => ['app', 'routes', 'resources/views', 'resources/js', 'resources/ts', 'lang'],
            'deny' => ['.env', '.env.*', '*.key', '*.pem', 'auth.json', '*secret*', '*credential*'],
            'max_file_bytes' => 60_000,
            // Hide things that look like secrets (API keys, tokens, private
            // keys, long quoted passwords) in code and pages before they are
            // sent. Add your own as label => regular expression.
            'redact' => true,
            'redact_patterns' => [],
        ],

        // Anything Claude should know that the code doesn't say: the sample
        // data in your seeders, which account the recorder signs in as, words
        // your users use for things.
        'notes' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        // Stop a video's generation once its cost passes this, in US dollars
        'max_cost_per_video' => 2.00,
        // Requests to Claude while it reads code and writes the plan
        'max_turns' => 30,
        // Times Claude may fix the plan after a step fails in the browser
        'max_repairs' => 2,
        'max_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recorder
    |--------------------------------------------------------------------------
    |
    | Playwright drives the app at "base_url" and records the screen, then
    | ffmpeg converts the recording to MP4. Run php artisan video-demo:install
    | once to install Playwright and its browser.
    |
    */

    'recorder' => [
        'base_url' => env('VIDEO_DEMO_BASE_URL', env('APP_URL', 'http://localhost')),

        // The user the recorder signs in as before recording, by id or email.
        // Leave null to record signed out. Sign-in happens through a signed
        // link that only exists while the studio is enabled.
        'login_as' => env('VIDEO_DEMO_LOGIN_AS'),
        'guard' => null,

        // Playwright's own Chromium can't play MP4 (H.264) video. If your app
        // shows videos, set this to "chrome" to record with installed Chrome.
        'channel' => env('VIDEO_DEMO_BROWSER_CHANNEL'),
        'ignore_https_errors' => true,

        'node' => env('VIDEO_DEMO_NODE', 'node'),
        'npm' => env('VIDEO_DEMO_NPM', 'npm'),
        // A full path when ffmpeg isn't on the PATH, or a wrapper script for a
        // bundled copy that needs its own library folder (see the README)
        'ffmpeg' => env('VIDEO_DEMO_FFMPEG', 'ffmpeg'),

        // Where video-demo:install puts Playwright. The app's own node_modules
        // is searched too, so an app that already has Playwright needs nothing.
        'node_modules' => env('VIDEO_DEMO_NODE_MODULES', storage_path('video-demo/recorder/node_modules')),

        'timeout' => 600,

        // Videos play this many times faster than they were recorded, which
        // makes clicks and typing snappy. Captions, title cards and pauses
        // are held longer while recording, so they keep a readable length.
        'speed' => (float) env('VIDEO_DEMO_SPEED', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Devices
    |--------------------------------------------------------------------------
    |
    | The versions a video can be recorded in. Claude writes one plan for all
    | of them; each extra version only adds recording time on your machine.
    | Each is recorded at exactly this size. "touch" devices show taps
    | instead of a cursor. "default_devices" are switched on in the
    | new-video form.
    |
    */

    'devices' => [
        'desktop' => ['label' => 'Desktop', 'width' => 1280, 'height' => 800, 'touch' => false],
        'tv' => ['label' => 'TV', 'width' => 1920, 'height' => 1080, 'touch' => false],
        'tablet' => ['label' => 'Tablet', 'width' => 820, 'height' => 1180, 'touch' => true],
        'mobile' => ['label' => 'Mobile', 'width' => 390, 'height' => 844, 'touch' => true],
    ],

    'default_devices' => ['desktop'],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Generation runs as a queued job. Run a worker while using the studio:
    | php artisan queue:work --timeout=900
    |
    */

    'queue' => [
        'connection' => env('VIDEO_DEMO_QUEUE_CONNECTION'),
        'name' => env('VIDEO_DEMO_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Appearance
    |--------------------------------------------------------------------------
    */

    'ui' => [
        'name' => env('APP_NAME', 'Laravel').' videos',
        'logo' => null,
        // Buttons and highlights. The default near-black turns light in dark mode.
        'accent' => '#171717',
        // Geist, from Bunny Fonts (no tracking). Set to null to use system fonts only.
        'font_url' => 'https://fonts.bunny.net/css?family=geist:400,500,600|geist-mono:400,500&display=swap',
        // The studio's Examples page: sample videos made with this package,
        // streamed from its GitHub repository through jsDelivr. Set to false
        // to hide the page, or point examples_url at your own copy.
        'examples' => env('VIDEO_DEMO_EXAMPLES', true),
        'examples_url' => env('VIDEO_DEMO_EXAMPLES_URL', Examples::DEFAULT_URL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video styles
    |--------------------------------------------------------------------------
    |
    | The styles offered when making a video. These are copied into the
    | database the first time they are needed; after that, add and edit
    | styles on the studio's Styles page. "direction" tells Claude how to
    | write the video. "theme" (light or dark) and "accent" set the look of
    | title cards and captions, "captions" puts them at the top or bottom,
    | "pace" (relaxed, normal or brisk) sets how fast the video moves, and
    | "art" (none or abstract) puts animated line art behind title cards,
    | with the words rising into place, like an ad.
    |
    */

    'styles' => [
        'clean' => [
            'name' => 'Clean walkthrough',
            'direction' => 'Calm and clear. Open with a title card that says what the viewer will be able to do, explain each part of the flow with a short caption, and close with a card that sums up the result.',
            'theme' => 'light',
            'accent' => '#171717',
            'captions' => 'bottom',
            'pace' => 'normal',
        ],
        'quick' => [
            'name' => 'Quick tip',
            'direction' => 'As short as it can be. No title cards. One caption at the start saying what the viewer will learn, then the steps with no extra explanation, ending on the result.',
            'theme' => 'dark',
            'accent' => '#171717',
            'captions' => 'bottom',
            'pace' => 'brisk',
        ],
        'detailed' => [
            'name' => 'Detailed tutorial',
            'direction' => 'For people new to the app. Explain why each step matters, point out optional fields and what happens after saving, and start a chapter for each part of the flow.',
            'theme' => 'light',
            'accent' => '#171717',
            'captions' => 'bottom',
            'pace' => 'relaxed',
        ],
        'showcase' => [
            'name' => 'Product showcase',
            'direction' => 'For a marketing page. Lead with the benefit to the user, keep captions upbeat but plain, linger on the finished result, and end on a card stating the outcome.',
            'theme' => 'dark',
            'accent' => '#171717',
            'captions' => 'top',
            'pace' => 'normal',
            'art' => 'abstract',
        ],
        'ad' => [
            'name' => 'Promo ad',
            'direction' => 'A short ad. Open on a card with one bold claim about what the user gains, in under eight words. Cut quickly through the two or three moments that prove it, each with a caption of under six words. Close on a card that names the result and says where to start. No step-by-step explanation.',
            'theme' => 'light',
            'accent' => '#c4532d',
            'captions' => 'bottom',
            'pace' => 'brisk',
            'art' => 'abstract',
        ],
    ],

];
