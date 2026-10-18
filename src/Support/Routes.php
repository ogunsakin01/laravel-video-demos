<?php

namespace Ogunsakin01\VideoDemo\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionClass;
use ReflectionFunction;
use Throwable;

/**
 * Finds the app's route for a path a video opens, such as /jobs/42, and the
 * files that serve it.
 */
final class Routes
{
    /**
     * Get the GET route that answers a path, or null when none does.
     */
    public static function match(string $path): ?Route
    {
        try {
            $route = Router::getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return null;
        }

        // The fallback route answers every path, so it says nothing about this one.
        return $route->isFallback ? null : $route;
    }

    /**
     * Describe the routes a video opens: each route's name (when it has one)
     * and its URI, such as "jobs/{job}", once each, in order.
     *
     * @param  list<string>  $paths
     * @return list<array{name: ?string, uri: string}>
     */
    public static function describe(array $paths): array
    {
        $routes = [];
        $frontend = (bool) config('video-demo.frontend.url');
        foreach ($paths as $path) {
            if ($route = self::match($path)) {
                $routes[$route->uri()] = ['name' => $route->getName(), 'uri' => $route->uri()];
            } elseif ($frontend) {
                // A separate frontend's pages aren't Laravel routes: they are kept by their path.
                $uri = trim((string) parse_url($path, PHP_URL_PATH), '/');
                $routes[$uri] ??= ['name' => null, 'uri' => $uri];
            }
        }

        return array_values($routes);
    }

    /**
     * Get the files behind a path, relative to the app's root: the
     * controller that serves it, or the routes file of a closure.
     *
     * @return list<string>
     */
    public static function files(string $path): array
    {
        $route = self::match($path);
        if (! $route) {
            return [];
        }

        try {
            $uses = $route->getAction('uses');
            $file = match (true) {
                $uses instanceof Closure => (new ReflectionFunction($uses))->getFileName(),
                is_string($uses) && ($class = $route->getControllerClass()) !== null => (new ReflectionClass($class))->getFileName(),
                default => false,
            };
        } catch (Throwable) {
            return [];
        }

        $root = rtrim(base_path(), '/').'/';

        return is_string($file) && str_starts_with($file, $root) ? [substr($file, strlen($root))] : [];
    }
}
