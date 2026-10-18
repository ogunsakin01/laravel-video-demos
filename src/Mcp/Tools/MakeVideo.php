<?php

namespace Ogunsakin01\VideoDemo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Ogunsakin01\VideoDemo\Mcp\Json;
use Ogunsakin01\VideoDemo\VideoDemo;
use RuntimeException;

#[IsDestructive(false)]
#[Description('Queue a how-to video of a flow in the app. Spends the app owner\'s AI credit (usually $0.05 to $0.70) within the video\'s spending limit: only call it when asked for a video. Returns at once; a queue worker makes it, so follow it with get-video.')]
class MakeVideo extends Tool
{
    public function handle(Request $request, VideoDemo $demo): Response
    {
        $options = array_filter([
            'length' => $request->get('length'),
            'style' => $request->get('style'),
            'devices' => $request->get('devices'),
            'as' => $request->get('as'),
            'max_cost' => $request->get('max_cost'),
            'start' => $request->get('start'),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        try {
            $video = $demo->make((string) $request->get('description'), $options);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Json::response([
            'slug' => $video->slug,
            'status' => $video->status->value,
            'estimate' => ['low' => $video->estimate_low, 'high' => $video->estimate_high],
            'spending_limit' => $video->spendLimit(),
            'studio_url' => route('video-demo.studio.show', $video),
            'next' => 'A queue worker makes it (php artisan video-demo:work). Check on it with get-video.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()->description('The flow, the way the app\'s users would ask about it, with where it starts and how it ends')->required(),
            'length' => $schema->integer()->description('Target length in seconds (60 by default)'),
            'style' => $schema->string()->description('A style key: clean, quick, detailed, showcase, ad, spotlight or promo'),
            'devices' => $schema->array()->items($schema->string())->description('Versions to record: desktop, tv, tablet, mobile'),
            'as' => $schema->string()->description('The type of user to record as, such as "admin", or "guest"'),
            'start' => $schema->string()->description('The path it starts on, such as /dashboard'),
            'max_cost' => $schema->number()->description('Spending limit in US dollars'),
        ];
    }
}
