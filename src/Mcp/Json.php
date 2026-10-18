<?php

namespace Ogunsakin01\VideoDemo\Mcp;

use Laravel\Mcp\Response;

/**
 * A tool's result as readable JSON text, which agents read and parse alike.
 */
final class Json
{
    public static function response(mixed $data): Response
    {
        return Response::text((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
