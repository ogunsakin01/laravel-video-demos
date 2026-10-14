<?php

namespace Ogunsakin01\VideoDemo\Ai;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Lists what decides who may open a page or use a feature: the app's own
 * middleware, its policies and its gates, with the files that hold them, so
 * the model can read them and seed the data a user needs to pass.
 */
class AccessRules
{
    /**
     * Middleware every Laravel app has, which needs no explaining.
     */
    protected const FRAMEWORK = ['web', 'api', 'throttle', 'bindings', 'cache.headers', 'signed', 'guest', 'auth', 'auth.basic', 'auth.session', 'verified', 'password.confirm', 'precognitive', 'can'];

    /**
     * Describe the access rules, or return an empty string when there are none.
     */
    public function describe(): string
    {
        return implode("\n\n", array_filter([
            $this->middleware(),
            $this->policies(),
            $this->gates(),
        ]));
    }

    /**
     * List the app's own middleware: aliases the routes use and middleware
     * added to the web group, such as a check that sends users to onboarding.
     */
    protected function middleware(): string
    {
        $aliases = Router::getMiddleware();
        $used = collect(Router::getRoutes()->getRoutes())
            ->flatMap(fn ($route) => array_filter($route->gatherMiddleware(), is_string(...)))
            ->map(fn (string $m) => Str::before($m, ':'))
            ->unique()
            ->reject(fn ($alias) => in_array($alias, self::FRAMEWORK, true) || ! isset($aliases[$alias]));

        $lines = $used->map(fn ($alias) => "- $alias: ".$this->where($aliases[$alias]))->values()->all();

        foreach ((array) (Router::getMiddlewareGroups()['web'] ?? []) as $class) {
            if (is_string($class) && str_starts_with($class, 'App\\')) {
                $lines[] = '- every web page: '.$this->where($class);
            }
        }

        return $lines ? "Middleware (the routes list where each is used):\n".implode("\n", $lines) : '';
    }

    /**
     * List the policies, registered or found in app/Policies.
     */
    protected function policies(): string
    {
        $lines = [];
        foreach (Gate::policies() as $model => $policy) {
            $lines[$policy] = '- '.class_basename($model).': '.$this->where($policy);
        }
        foreach (glob(app_path('Policies/*.php')) ?: [] as $file) {
            $class = 'App\\Policies\\'.basename($file, '.php');
            $lines[$class] ??= '- '.Str::beforeLast(class_basename($class), 'Policy').': '.$this->relative($file);
        }

        return $lines ? "Policies (checked with authorize(), can() and @can):\n".implode("\n", $lines) : '';
    }

    /**
     * List the gates defined with Gate::define.
     */
    protected function gates(): string
    {
        $names = array_keys(Gate::abilities());
        $names = array_values(array_filter($names, fn ($n) => ! str_starts_with($n, 'viewVideoDemo')));

        return $names ? 'Gates (defined with Gate::define; search the code for them): '.implode(', ', array_slice($names, 0, 60)) : '';
    }

    /**
     * Say where a class is: its file when it is the app's own, otherwise its name.
     */
    protected function where(mixed $class): string
    {
        if (! is_string($class)) {
            return 'a closure';
        }

        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return $class;
        }

        return $file && str_starts_with($file, base_path().'/') && ! str_contains($file, '/vendor/')
            ? $this->relative($file)
            : "$class (a package)";
    }

    protected function relative(string $file): string
    {
        return ltrim(Str::after($file, base_path()), '/');
    }
}
