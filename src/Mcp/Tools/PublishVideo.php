<?php

namespace Ogunsakin01\VideoDemo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Ogunsakin01\VideoDemo\VideoDemo;
use Throwable;

#[Description('Publish a ready video to the app\'s help library, where users see it, or take it out with unpublish. Only when asked to.')]
class PublishVideo extends Tool
{
    public function handle(Request $request, VideoDemo $demo): Response
    {
        $video = $demo->find((string) $request->get('video'));
        if (! $video) {
            return Response::error('There is no video ['.$request->get('video').'].');
        }

        try {
            $request->get('unpublish') ? $demo->unpublish($video) : $demo->publish($video);
        } catch (Throwable $e) {
            return Response::error($e->getMessage());
        }

        return Response::text($request->get('unpublish') ? "Took {$video->slug} out of the library." : "Published {$video->slug}: ".route('video-demo.'.($video->visibility === 'private' ? 'private' : 'public').'.show', $video->slug));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'video' => $schema->string()->description('The video\'s slug or id')->required(),
            'unpublish' => $schema->boolean()->description('Take it out of the library instead'),
        ];
    }
}
