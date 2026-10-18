<?php

namespace Ogunsakin01\VideoDemo\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Ogunsakin01\VideoDemo\Mcp\Json;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\VideoDemo;

#[IsReadOnly]
#[Description('List the app\'s videos: slug, title, status, whether published, length and AI cost.')]
class ListVideos extends Tool
{
    public function handle(Request $request, VideoDemo $demo): Response
    {
        return Json::response($demo->videos()->map(fn (Video $video) => [
            'slug' => $video->slug,
            'title' => $video->title,
            'status' => $video->status->value,
            'published' => $video->isPublished(),
            'seconds' => $video->duration_seconds,
            'cost' => round($video->cost, 4),
        ])->values()->all());
    }
}
