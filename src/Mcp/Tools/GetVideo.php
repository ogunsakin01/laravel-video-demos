<?php

namespace Ogunsakin01\VideoDemo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Ogunsakin01\VideoDemo\Mcp\Json;
use Ogunsakin01\VideoDemo\VideoDemo;

#[IsReadOnly]
#[Description('Get one video: its status, why it failed if it did, its latest progress, its plan\'s steps, cost, and where to watch it in the studio and the library.')]
class GetVideo extends Tool
{
    public function handle(Request $request, VideoDemo $demo): Response
    {
        $video = $demo->find((string) $request->get('video'));
        if (! $video) {
            return Response::error('There is no video ['.$request->get('video').'].');
        }

        return Json::response([
            'slug' => $video->slug,
            'title' => $video->title,
            'status' => $video->status->value,
            'error' => $video->error,
            'issues' => $video->issues,
            'progress' => array_column(array_slice($video->log ?? [], -8), 'message'),
            'steps' => count($video->plan['steps'] ?? []),
            'seconds' => $video->duration_seconds,
            'cost' => round($video->cost, 4),
            'published' => $video->isPublished(),
            'studio_url' => route('video-demo.studio.show', $video),
            'library_url' => $video->isPublished() ? route('video-demo.'.($video->visibility === 'private' ? 'private' : 'public').'.show', $video->slug) : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'video' => $schema->string()->description('The video\'s slug or id')->required(),
        ];
    }
}
