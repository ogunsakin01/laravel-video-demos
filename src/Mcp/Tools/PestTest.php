<?php

namespace Ogunsakin01\VideoDemo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Ogunsakin01\VideoDemo\Mcp\Json;
use Ogunsakin01\VideoDemo\Recording\PestTest as Test;
use Ogunsakin01\VideoDemo\VideoDemo;
use RuntimeException;

#[IsReadOnly]
#[Description('Turn a video\'s plan into a Pest browser test (pestphp/pest-plugin-browser): the flow it shows, as a test that it still works. Returns the file\'s contents and a suggested path. No AI, no cost.')]
class PestTest extends Tool
{
    public function handle(Request $request, VideoDemo $demo): Response
    {
        $video = $demo->find((string) $request->get('video'));
        if (! $video) {
            return Response::error('There is no video ['.$request->get('video').'].');
        }

        try {
            return Json::response(['path' => 'tests/Browser/'.Test::fileName($video), 'contents' => $demo->pestTest($video)]);
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'video' => $schema->string()->description('The video\'s slug or id')->required(),
        ];
    }
}
