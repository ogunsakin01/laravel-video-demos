<?php

namespace Ogunsakin01\VideoDemo\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Ogunsakin01\VideoDemo\Mcp\Json;
use Ogunsakin01\VideoDemo\Support\Doctor;

#[IsReadOnly]
#[Description('Check everything making videos needs (the AI key, the app\'s address, Node, Playwright, ffmpeg, the queue and a worker, the user to record as, sample data, the storage disk) and say how to fix what isn\'t right. Costs nothing.')]
class CheckSetup extends Tool
{
    public function handle(Request $request, Doctor $doctor): Response
    {
        $checks = $doctor->run();

        return Json::response([
            'ok' => ! collect($checks)->contains('status', 'fail'),
            'checks' => $checks,
        ]);
    }
}
