<?php

use Ogunsakin01\VideoDemo\Studio\Examples;

return [

    /*
    |--------------------------------------------------------------------------
    | Studio
    |--------------------------------------------------------------------------
    |
    | The screens where developers create, preview and publish videos. The AI
    | model reads the app's code and the recorder drives the app in a browser,
    | so the studio is meant to run on a developer's machine only. Its routes
    | are not registered at all when it is disabled, and never in production
    | unless "allow_in_production" is set.
    |
    */

    'studio' => [
        // On in the local environment by default. Set VIDEO_DEMO_STUDIO to
        // turn it on or off anywhere else.
        'enabled' => env('VIDEO_DEMO_STUDIO', env('APP_ENV') === 'local'),
        'allow_in_production' => false,

        // Outside local, only users this gate allows may use the studio. With
        // no such gate defined, the studio is closed outside local.
        'gate' => 'viewVideoDemoStudio',
        'prefix' => 'video-demo/studio',
        'middleware' => ['web'],
        'domain' => null,

        // Your production address, so the studio can show what users asked
        // for there. It needs video-demo.library.requests_token set on both
        // sides.
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

        // Let visitors ask for a video when a search finds nothing.
        'accept_requests' => true,

        // Count how the videos are watched: plays, how far viewers get, and
        // library searches that found nothing. Only daily totals are kept,
        // with no cookies, IP addresses or user ids. The studio shows them
        // on each video's page, read from production with requests_token.
        'stats' => (bool) env('VIDEO_DEMO_STATS', false),

        // Lets the studio on your machine fetch those requests from production.
        // It is sent as a bearer token. Leave it empty to turn the endpoint off.
        'requests_token' => env('VIDEO_DEMO_REQUESTS_TOKEN'),

        // How long production caches the manifest, in seconds.
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
    | AI Model
    |--------------------------------------------------------------------------
    |
    | The models that plan videos. Each app owner uses their own API key.
    | Prices are US dollars per million tokens and are used for the cost
    | preview and for each video's cost. Check your provider's pricing page
    | (https://platform.claude.com/docs/en/about-claude/pricing for Claude)
    | and update them when prices change.
    |
    */

    'ai' => [
        // The Anthropic API key. Keys for other providers go in config/prism.php.
        'api_key' => env('ANTHROPIC_API_KEY'),

        // The model new videos start with. Left empty, the cheapest model
        // marked "reliable" below whose provider has an API key is used
        // (Claude Sonnet 5.5 out of the box).
        'model' => env('VIDEO_DEMO_MODEL'),

        // How much Claude thinks before it answers: low, medium, high, xhigh
        // or max. Other providers ignore it.
        'effort' => env('VIDEO_DEMO_EFFORT', 'high'),

        // How long to wait for one reply from the model, in seconds.
        'timeout' => 600,

        // When Claude Opus or Sonnet declines a request, let the API retry it
        // on another model instead of failing. Anthropic models only.
        'fallbacks' => true,

        // Each model's provider and prices (US dollars per million tokens).
        // "anthropic" models use the Anthropic SDK. Any other provider goes
        // through Prism (composer require prism-php/prism), with its key in
        // config/prism.php. Leave prices out and the cost shows as unknown.
        'models' => [
            'claude-opus-5-5' => ['label' => 'Claude Opus 5.5', 'provider' => 'anthropic', 'reliable' => true, 'input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20],
            'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5', 'provider' => 'anthropic', 'reliable' => true, 'input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10],
            // Haiku costs 5 times as much for a request whose prompt (cached
            // tokens included) is over 100K tokens. It is cheap, but plans less
            // reliably, so it isn't picked by default.
            'claude-haiku-5-5' => ['label' => 'Claude Haiku 5.5', 'provider' => 'anthropic', 'reliable' => false, 'input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01,
                'long_context' => ['above' => 100_000, 'multiplier' => 5]],

            // Other providers go through Prism, with their keys in
            // config/prism.php (OPENAI_API_KEY, GEMINI_API_KEY and so on).
            // Prices as listed in October 2026; check your provider's pricing
            // page. Mark a model reliable only once it has planned a few videos
            // well.
            // 'gpt-6.1-sol' => ['label' => 'GPT-6.1 Sol', 'provider' => 'openai', 'reliable' => true, 'input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10],
            // 'gemini-3.8-flash' => ['label' => 'Gemini 3.8 Flash', 'provider' => 'gemini', 'reliable' => false, 'input' => 0.75, 'output' => 3.75, 'cache_read' => 0.075],
            // 'qwen3-coder' => ['label' => 'Qwen3 Coder (local)', 'provider' => 'ollama', 'reliable' => false, 'input' => 0, 'output' => 0],
        ],

        // Writing to the 5-minute prompt cache costs this many times the
        // input price.
        'cache_write_multiplier' => 1.25,

        // Writing to the 1-hour prompt cache, used while long recordings run,
        // costs this many times the input price.
        'cache_write_1h_multiplier' => 2.0,

        // Folders the AI model may read, relative to the app's root. Files
        // matching "deny" are never read, wherever they are.
        'code' => [
            'paths' => ['app', 'routes', 'resources/views', 'resources/js', 'resources/ts', 'lang'],
            'deny' => ['.env', '.env.*', '*.key', '*.pem', 'auth.json', '*secret*', '*credential*'],

            // The most of a file sent in one read, in bytes. Longer files are
            // read in parts.
            'max_file_bytes' => 60_000,

            // Hide things that look like secrets (API keys, tokens, private
            // keys, long quoted passwords) in code and pages before they are
            // sent. Add your own as label => regular expression.
            'redact' => true,
            'redact_patterns' => [],
        ],

        // Anything the AI model should know that the code doesn't say, such
        // as the sample data in your seeders, which account the recorder signs
        // in as, or the words your users use for things.
        'notes' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | These limits keep a video from spending too much or running too long.
    | The cost limit is the default for each new video; the form and the
    | generate command can set a different one per video.
    |
    */

    'limits' => [
        // The spending limit of each new video, in US dollars, unless a video
        // sets its own. The AI plans within it and stops before going over.
        'max_cost_per_video' => (float) env('VIDEO_DEMO_MAX_COST', 0.50),

        // The most requests to the AI model while it reads code and writes
        // the plan.
        'max_turns' => 30,

        // The most times the AI model may fix the plan after a step fails in
        // the browser.
        'max_repairs' => 2,

        // The longest video that can be asked for, in seconds.
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

        // The auth guard the recorder signs in with. Null uses the app's
        // default guard.
        'guard' => null,

        // The types of user a video can be recorded as. Found from the app's
        // roles (spatie/laravel-permission, Laratrust, Bouncer, a role_id or
        // role column, or an is_admin flag). If the app models them some
        // other way, list them here: label => the user to sign in as.
        // 'user_types' => ['Admin' => 'admin@example.com', 'Customer' => 'jane@example.com'],
        'user_types' => [],

        // Playwright's own Chromium can't play MP4 (H.264) video. If your app
        // shows videos, set this to "chrome" to record with installed Chrome.
        'channel' => env('VIDEO_DEMO_BROWSER_CHANNEL'),

        // Accept invalid or self-signed HTTPS certificates, as local sites
        // often have.
        'ignore_https_errors' => true,

        // The node and npm commands, or their full paths.
        'node' => env('VIDEO_DEMO_NODE', 'node'),
        'npm' => env('VIDEO_DEMO_NPM', 'npm'),

        // A full path when ffmpeg isn't on the PATH, or a wrapper script for a
        // bundled copy that needs its own library folder: https://github.com/ogunsakin01/laravel-video-demos/wiki/Installation
        'ffmpeg' => env('VIDEO_DEMO_FFMPEG', 'ffmpeg'),

        // Where video-demo:install puts Playwright. The app's own node_modules
        // is searched too, so an app that already has Playwright needs nothing.
        'node_modules' => env('VIDEO_DEMO_NODE_MODULES', storage_path('video-demo/recorder/node_modules')),

        // The least time each recording may take, in seconds. Long videos get
        // more.
        'timeout' => 600,

        // Open the start page before planning, and every page in the plan
        // before recording, as the user the video is recorded as. A broken
        // page stops the video before any AI is spent on it.
        'check_pages' => true,

        // Videos play this many times faster than they were recorded. At 1
        // the cursor, typing and scrolling move at a person's pace; time spent
        // waiting on the app (pages loading) is cut out either way. Captions,
        // title cards and pauses keep a readable length at any speed. This is
        // the default for new videos; each video can set its own.
        'speed' => (float) env('VIDEO_DEMO_SPEED', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Voice-over and Music
    |--------------------------------------------------------------------------
    |
    | Videos are silent unless a voice or music is chosen for them, on the
    | form, per style, or here for every new video. The AI writes a short line
    | for each step, a voice speaks it, and the recorder waits for each line
    | to finish. Music plays underneath and drops while the voice speaks.
    |
    | Local voices are free and run on this machine; install them with
    | php artisan video-demo:install-voice. The computer's own voice (macOS
    | "say", Windows speech, or espeak-ng on Linux) needs no install and is
    | meant for drafts. OpenAI, Gemini and ElevenLabs voices appear when their
    | key is set. Clips are saved, so recording again costs nothing.
    |
    */

    'voice' => [
        // The voice new videos start with, such as "kokoro:af_heart". Empty for none.
        'default' => env('VIDEO_DEMO_VOICE'),

        // Where the local voice model is kept. Outside the project, so it is
        // never committed, and shared by every project on this machine.
        'cache_path' => env('VIDEO_DEMO_VOICE_CACHE'),

        // The local voices offered, voice id => label: Kokoro's best-rated ones.
        'kokoro_voices' => [
            'af_heart' => 'Heart, warm (US)',
            'af_bella' => 'Bella, bright (US)',
            'af_nicole' => 'Nicole, soft (US)',
            'am_michael' => 'Michael, steady (US)',
            'am_fenrir' => 'Fenrir, bold (US)',
            'bf_emma' => 'Emma, calm (UK)',
            'bf_isabella' => 'Isabella, friendly (UK)',
            'am_puck' => 'Puck, lively (US)',
            'bm_george' => 'George, warm (UK)',
            'bm_fable' => 'Fable, storyteller (UK)',
        ],

        // Voices from AI providers, offered when their key is set. The price
        // is US dollars per 1,000 characters spoken, added to the video's
        // cost; these are estimates from October 2026, so check your
        // provider's price list. A minute of speech is about 900 characters.
        'providers' => [
            'openai' => [
                'key' => env('OPENAI_API_KEY'),
                'model' => 'gpt-4o-mini-tts',
                'price_per_1k_chars' => 0.017,
                'voices' => [
                    'marin' => 'Marin, natural', 'cedar' => 'Cedar, natural', 'coral' => 'Coral, warm',
                    'sage' => 'Sage, calm', 'ash' => 'Ash, clear', 'nova' => 'Nova, bright',
                    'onyx' => 'Onyx, deep', 'shimmer' => 'Shimmer, soft',
                ],
            ],
            'gemini' => [
                'key' => env('GEMINI_API_KEY'),
                'model' => 'gemini-3.8-flash-tts',
                'price_per_1k_chars' => 0.015,
                'voices' => [
                    'Kore' => 'Kore, firm', 'Puck' => 'Puck, upbeat', 'Charon' => 'Charon, informative',
                    'Aoede' => 'Aoede, breezy', 'Sulafat' => 'Sulafat, warm', 'Achird' => 'Achird, friendly',
                    'Iapetus' => 'Iapetus, clear', 'Vindemiatrix' => 'Vindemiatrix, gentle',
                ],
            ],
            // ElevenLabs offers the voices in your account, including your own.
            'elevenlabs' => [
                'key' => env('ELEVENLABS_API_KEY'),
                'model' => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),
                'price_per_1k_chars' => 0.08,
            ],
        ],
    ],

    'music' => [
        // The track new videos start with: a track's key, or empty for none.
        'default' => env('VIDEO_DEMO_MUSIC'),

        // How loud the music is, from 0 to 1. It drops to a quarter of this
        // while the voice speaks.
        'volume' => 0.25,

        // Tracks of your own, key => absolute path to an MP3, WAV, M4A, OGG or
        // FLAC file. Tracks uploaded on the Styles page are added to these.
        'tracks' => [],

        // Offer the package's four built-in tracks (calm, upbeat, corporate,
        // cinematic), composed for it and free to use. Each is downloaded the
        // first time a video uses it.
        'builtin' => (bool) env('VIDEO_DEMO_BUILT_IN_MUSIC', true),
        'builtin_url' => env('VIDEO_DEMO_BUILT_IN_MUSIC_URL', 'https://cdn.jsdelivr.net/gh/ogunsakin01/laravel-video-demos@v0.12.0/docs/music'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sample Data
    |--------------------------------------------------------------------------
    |
    | By default, a video is recorded against a
    | temporary SQLite database instead of the app's own. A base database is
    | built once (your migrations, then these seeders, then a user of each
    | type) and copied for each video; it is built again when a migration,
    | seeder or factory changes. Build it ahead of time with
    | php artisan video-demo:sample-database. While planning, the AI reads
    | the routes' middleware, policies and gates and adds whatever else the
    | flow needs to the video's copy. Only the recorder's browser uses it, and
    | mail, broadcasts and queued jobs are kept local while it does.
    |
    */

    'sample_data' => [
        // New videos are recorded on sample data unless this is false. Each
        // video can still choose; set VIDEO_DEMO_SAMPLE_DATA=false to record
        // on your own local data by default.
        'default' => (bool) env('VIDEO_DEMO_SAMPLE_DATA', true),

        // The seeders to run first. Empty leaves it all to the AI. Seeders
        // that are missing are skipped, and one that fails is reported.
        'seeders' => ['Database\\Seeders\\DatabaseSeeder'],

        // The artisan file that builds the database, in its own PHP process.
        // Null uses the app's artisan.
        'artisan' => null,

        // Seconds allowed for building the base database (migrating and seeding).
        'timeout' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Devices
    |--------------------------------------------------------------------------
    |
    | The versions a video can be recorded in. The AI model writes one plan
    | for all of them; each extra version only adds recording time on your
    | machine. Each is recorded at exactly this size. "touch" devices show
    | taps instead of a cursor. "default_devices" are switched on in the
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
    | php artisan queue:work video-demo
    |
    */

    'queue' => [
        // Leave empty to use the package's own "video-demo" connection: your
        // default connection, with retry_after long enough for long videos
        // (otherwise a job running past retry_after is started a second
        // time, and paid for twice), on its own queue. Name a connection to
        // use it instead.
        'connection' => env('VIDEO_DEMO_QUEUE_CONNECTION'),

        // The queue the package's jobs are pushed to.
        'name' => env('VIDEO_DEMO_QUEUE', 'video-demo'),

        // Seconds a job may run before the queue starts it again, for the
        // package's own connection. Keep it longer than your longest video.
        'retry_after' => 7200,

        // How many videos php artisan video-demo:work makes at once. Each
        // runs its own browser, so more needs more memory and CPU.
        'workers' => (int) env('VIDEO_DEMO_WORKERS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Appearance
    |--------------------------------------------------------------------------
    |
    | The name, logo, colours and font used by the studio and library pages,
    | and whether the studio shows its Examples page.
    |
    */

    'ui' => [
        // Shown in the studio and library headers.
        'name' => env('VIDEO_DEMO_NAME', env('APP_NAME', 'Laravel').' videos'),

        // The URL of an image shown next to the name, or null for none.
        'logo' => null,

        // The browser tab icon. Left empty, the package's own icon is used.
        // "app" uses the app's favicon from public/ (favicon.svg, .ico, .png,
        // .webp, .jpg or apple-touch-icon.png); a URL uses that image.
        'favicon' => env('VIDEO_DEMO_FAVICON'),

        // The colour of buttons and highlights. The default near-black turns
        // light in dark mode.
        'accent' => '#171717',

        // Geist, from Bunny Fonts (no tracking). Set to null to use system
        // fonts only.
        'font_url' => 'https://fonts.bunny.net/css?family=geist:400,500,600|geist-mono:400,500&display=swap',

        // The studio's Examples page: sample videos made with this package,
        // streamed from its GitHub repository through jsDelivr. Set to false
        // to hide the page, or point examples_url at your own copy.
        'examples' => env('VIDEO_DEMO_EXAMPLES', true),
        'examples_url' => env('VIDEO_DEMO_EXAMPLES_URL', Examples::DEFAULT_URL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video Styles
    |--------------------------------------------------------------------------
    |
    | The styles offered when making a video. These are copied into the
    | database the first time they are needed; after that, add and edit
    | styles on the studio's Styles page. "direction" tells the AI model how
    | to write the video. "theme" (light or dark) and "accent" set the look
    | of title cards and captions, "captions" puts them at the top or bottom,
    | "pace" (relaxed, normal or brisk) sets how fast the video moves, and
    | "art" (none, abstract or headline) puts animated line art behind title
    | cards with the words rising into place, like an ad, or draws cards and
    | captions as big two-line headlines, like a product promo. "app_theme" (auto, light
    | or dark) is the colour scheme the app is recorded in, for apps that
    | follow the system setting. "zoom" moves the camera in on each step and
    | "highlight" outlines what is being used, dimming the rest.
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
        'spotlight' => [
            'name' => 'Spotlight',
            'direction' => 'A polished feature reel. Open on a card with one bold line about what the viewer gains. Show each key moment with a caption of under eight words that names the benefit, not the button. Keep the steps that show something worth seeing and skip routine ones. Close on a card naming the result.',
            'theme' => 'dark',
            'accent' => '#e5693d',
            'captions' => 'bottom',
            'pace' => 'normal',
            'art' => 'abstract',
            // Record the app in dark mode, zoom in on each step, and outline what is being used.
            'app_theme' => 'dark',
            'zoom' => true,
            'highlight' => true,
        ],
        'promo' => [
            'name' => 'Promo reel',
            'direction' => 'A punchy product promo. Open on a card whose title is one bold claim of under six words, with a sentence of under eight words that backs it up. Show only the two to four moments that prove it, each with a caption: a title of under six words naming what the viewer gains, and a second line of a few words. Leave out routine steps. Close on a card naming the result. No step-by-step explanation.',
            'theme' => 'dark',
            'accent' => '#e5693d',
            'captions' => 'top',
            'pace' => 'brisk',
            // Big two-line headlines, the app in dark mode with the camera on each step, and upbeat music.
            'art' => 'headline',
            'app_theme' => 'dark',
            'zoom' => true,
            'highlight' => true,
            'music' => 'builtin:upbeat',
        ],
    ],

];
