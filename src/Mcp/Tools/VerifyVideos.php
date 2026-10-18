<?php

namespace Ogunsakin01\VideoDemo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Ogunsakin01\VideoDemo\Mcp\Json;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Recording\Verifier;
use Ogunsakin01\VideoDemo\VideoDemo;
use RuntimeException;

#[IsReadOnly]
#[Description('Check that published videos still work after a change, playing their plans in a browser without recording: which broke, at which step, and why. No AI, no cost. Give changed (a git ref such as origin/main) to check only the videos made from code that changed since.')]
class VerifyVideos extends Tool
{
    public function handle(Request $request, VideoDemo $demo): Response
    {
        $videos = $demo->videos()->filter(fn (Video $video) => $video->isPublished() && $video->hasPlan());
        if ($slugs = (array) $request->get('videos', [])) {
            $videos = $videos->filter(fn (Video $video) => in_array($video->slug, $slugs, true));
        }
        if ($ref = $request->get('changed')) {
            try {
                $changed = Verifier::changedFiles((string) $ref);
            } catch (RuntimeException $e) {
                return Response::error($e->getMessage());
            }
            $videos = $videos->filter(fn (Video $video) => Verifier::touched($video, $changed) !== []);
        }

        $results = $videos->map(fn (Video $video) => ['slug' => $video->slug, ...$demo->verify($video)])->values()->all();

        return Json::response(['ok' => ! collect($results)->contains('ok', false), 'videos' => $results]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'videos' => $schema->array()->items($schema->string())->description('Only these slugs'),
            'changed' => $schema->string()->description('Only videos made from code that differs from this git ref, such as origin/main'),
        ];
    }
}
