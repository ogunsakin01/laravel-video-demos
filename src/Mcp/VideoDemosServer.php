<?php

namespace Ogunsakin01\VideoDemo\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Ogunsakin01\VideoDemo\Mcp\Tools\CheckSetup;
use Ogunsakin01\VideoDemo\Mcp\Tools\GetVideo;
use Ogunsakin01\VideoDemo\Mcp\Tools\ListVideos;
use Ogunsakin01\VideoDemo\Mcp\Tools\MakeVideo;
use Ogunsakin01\VideoDemo\Mcp\Tools\PestTest;
use Ogunsakin01\VideoDemo\Mcp\Tools\PublishVideo;
use Ogunsakin01\VideoDemo\Mcp\Tools\VerifyVideos;

/**
 * The package's MCP server, for coding agents (Claude Code, Cursor and
 * others): make, check and publish the app's how-to videos while building
 * it. Registered as the local server "video-demos" when laravel/mcp is
 * installed and the studio is enabled: php artisan mcp:start video-demos.
 */
#[Name('Laravel Video Demos')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
    This app makes recorded how-to videos of itself: an AI model plans a flow from the code, a real browser records it, and published videos appear in the app's help library.
    - Start with check-setup when something fails or before the first video.
    - make-video spends the app owner's AI credit, within the video's spending limit ($0.50 by default): say what it may cost before calling it, and only when asked for a video. It queues the video and returns at once; a queue worker (php artisan video-demo:work) makes it. Follow it with get-video.
    - After changing views, routes or labels, verify-videos says which published videos the change broke, with no AI and no recording.
    - pest-test turns a video's plan into a Pest browser test, at no cost.
    TEXT)]
class VideoDemosServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Server\Tool>>
     */
    protected array $tools = [
        CheckSetup::class,
        ListVideos::class,
        GetVideo::class,
        MakeVideo::class,
        VerifyVideos::class,
        PublishVideo::class,
        PestTest::class,
    ];
}
