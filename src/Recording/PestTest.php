<?php

namespace Ogunsakin01\VideoDemo\Recording;

use Illuminate\Support\Str;
use Ogunsakin01\VideoDemo\Models\Video;

/**
 * Turns a video's plan into a Pest browser test (pestphp/pest-plugin-browser),
 * at no AI cost: the video's steps become a test that the flow still works,
 * so every help video doubles as a regression test.
 *
 * Elements are found the way Pest finds them: by a label, name or the exact
 * visible text, which is what the plan uses; a placeholder or a CSS selector
 * where that is all the plan has. Captions, cards, pauses and scrolling are
 * left out. A step whose element Pest can't be pointed at the same way gets
 * a comment saying so, rather than a guess.
 */
final class PestTest
{
    /**
     * Get the test for a video, as the contents of a PHP file.
     */
    public static function for(Video $video): string
    {
        $plan = Plan::fromArray($video->plan ?? [])->forDevice($video->primaryDevice());
        $title = (string) ($video->title ?: $plan->title ?: $video->slug);
        // Runs of chained calls on $page, and lines that stand alone (a frame, a comment).
        /** @var list<array{chain?: list<string>, lines?: list<string>}> $parts */
        $parts = [];
        $chain = function (string $call) use (&$parts): void {
            $last = array_key_last($parts);
            $last !== null && isset($parts[$last]['chain']) ? $parts[$last]['chain'][] = $call : $parts[] = ['chain' => [$call]];
        };
        $visited = false;

        foreach ($plan->steps as $step) {
            $action = (string) ($step['action'] ?? '');
            $target = array_filter((array) ($step['target'] ?? []));
            $value = (string) ($step['value'] ?? '');

            if ($action === 'sign_in') {
                // A browser test signs in before visiting, so the rest is the second half of a flow.
                $parts[] = ['lines' => ['// The video signs in here as '.(trim($value) !== '' ? trim($value) : 'the default user').'. Sign in on the page, or test what follows in its own test with actingAs().']];
                $visited ? $chain('navigate('.self::string((string) $step['url']).')') : $parts[] = ['lines' => ['$page = visit('.self::string((string) $step['url']).');']];
                $visited = true;

                continue;
            }
            if ($action === 'goto') {
                if (! $visited) {
                    $parts[] = ['lines' => ['$page = visit('.self::string((string) $step['url']).');']];
                    $visited = true;
                } else {
                    $chain('navigate('.self::string((string) $step['url']).')');
                }

                continue;
            }

            $call = match ($action) {
                'click' => in_array($target['role'] ?? null, ['checkbox', 'switch'], true)
                    ? self::call('check', $target)
                    : self::call('click', $target),
                'check' => self::call('check', $target),
                'type' => self::call('type', $target, [$value]),
                'select' => self::call('select', $target, [$value]),
                'hover' => self::call('hover', $target),
                'press' => 'keys('.self::string($target ? (self::locator($target) ?? ':focus') : ':focus').', '.self::string(strlen($value) > 1 ? '{'.$value.'}' : $value).')',
                'wait' => ($text = $target['text'] ?? $target['name'] ?? $target['label'] ?? null) !== null ? 'assertSee('.self::string((string) $text).')' : null,
                default => null,
            };
            if ($call === null) {
                continue;
            }

            if (str_starts_with($call, '//')) {
                $parts[] = ['lines' => [$call]];
            } elseif (! empty($target['frame'])) {
                // Fields inside an iframe, such as a card form, are reached through it.
                $parts[] = ['lines' => [
                    '$page->withinFrame('.self::string((string) $target['frame']).', function (AwaitableWebpage $frame) {',
                    '    $frame->'.$call.';',
                    '});',
                ]];
            } else {
                $chain($call);
            }
        }
        $chain('assertNoJavaScriptErrors()');

        $lines = [];
        foreach ($parts as $part) {
            $lines = [...$lines, ...(isset($part['chain'])
                ? ['$page'.implode('', array_map(fn ($c) => "\n    ->$c", $part['chain'])).';']
                : $part['lines']), ''];
        }

        $signIn = self::signIn($video);
        $body = [...$signIn, ...($signIn ? [''] : []), ...$lines];
        while (end($body) === '') {
            array_pop($body);
        }

        $model = RecordAs::model() ?? 'App\Models\User';
        $uses = array_filter([
            $signIn ? 'use '.ltrim($model, '\\').';' : null,
            str_contains(implode("\n", $body), 'AwaitableWebpage') ? 'use Pest\Browser\Api\AwaitableWebpage;' : null,
        ]);

        return implode("\n", [
            '<?php',
            '',
            '// Made by laravel-video-demos from the plan of "'.str_replace(['\\', "\n"], ['', ' '], $title).'" (version '.$video->version.').',
            '// Needs pestphp/pest-plugin-browser. The video was recorded on '.($video->sample_data ? 'sample data' : 'your local data').':',
            '// give the test the records its pages need, with factories or seeders.',
            ...($uses ? ['', ...$uses] : []),
            '',
            'test('.self::string($title).', function () {',
            ...array_map(fn ($line) => $line === '' ? '' : '    '.str_replace("\n", "\n    ", $line), $body),
            '});',
            '',
        ]);
    }

    /**
     * Get the lines that sign the test in as the kind of user the video was
     * recorded as: a factory user given the type's role (spatie's roles, a
     * role column or an admin flag), or one to adjust when the type is
     * modelled another way. None for a guest.
     *
     * @return list<string>
     */
    protected static function signIn(Video $video): array
    {
        if (! $video->record_as || $video->record_as === 'guest') {
            return [];
        }
        $class = class_basename(RecordAs::model() ?? 'App\Models\User');
        try {
            $type = RecordAs::types()[$video->record_as] ?? null;
        } catch (\Throwable) {
            $type = null;
        }
        $via = (string) ($type['via'] ?? '');
        $role = (string) ($type['role'] ?? '');

        return match (true) {
            $via === 'spatie' => [
                '$user = '.$class.'::factory()->create();',
                '$user->assignRole(\Spatie\Permission\Models\Role::findOrCreate('.self::string($role).'));',
                '$this->actingAs($user);',
            ],
            str_starts_with($via, 'column:') => ['$this->actingAs('.$class.'::factory()->create(['.self::string(substr($via, 7)).' => '.self::string($role).']));'],
            str_starts_with($via, 'flag:') => ['$this->actingAs('.$class.'::factory()->create(['.self::string(substr($via, 5)).' => '.($role === 'admin' ? 'true' : 'false').']));'],
            default => [
                '// Recorded as: '.RecordAs::label($video->record_as).'. Make the user that type.',
                '$this->actingAs('.$class.'::factory()->create());',
            ],
        };
    }

    /**
     * Get a Pest call for a target, or a comment when Pest can't be pointed at it.
     *
     * @param  array<string, string>  $target
     * @param  list<string>  $arguments
     */
    protected static function call(string $method, array $target, array $arguments = []): string
    {
        $locator = self::locator($target);
        if ($locator === null) {
            return '// '.ucfirst($method).' '.self::describe($target).': give it a label, a name or a data-test attribute to find it by.';
        }

        return $method.'('.implode(', ', array_map(self::string(...), [$locator, ...$arguments])).')';
    }

    /**
     * Get how Pest finds a target: its label, name or text (Pest matches those
     * exactly, and a label leads to its field), a placeholder, or a CSS
     * selector. Null when the plan has nothing Pest can use.
     *
     * @param  array<string, string>  $target
     */
    protected static function locator(array $target): ?string
    {
        if (($text = $target['label'] ?? $target['name'] ?? $target['text'] ?? null) !== null) {
            // Pest reads text with CSS characters ("Email:", "Save (draft)") as CSS, so point it at the exact text.
            return preg_match('/^[.#@\[]|[\[\]#>+~:*|^,=()]/', (string) $text)
                ? 'internal:text="'.str_replace('"', '\\"', (string) $text).'"s'
                : (string) $text;
        }
        if (isset($target['placeholder'])) {
            return '[placeholder="'.str_replace('"', '\\"', $target['placeholder']).'"]';
        }
        if (isset($target['selector'])) {
            // Pest only treats some strings as CSS; with "css=" Playwright always does.
            return 'css='.$target['selector'];
        }

        return null;
    }

    /**
     * Describe a target in a comment.
     *
     * @param  array<string, string>  $target
     */
    protected static function describe(array $target): string
    {
        return collect($target)->except('frame')->map(fn ($v, $k) => "$k \"$v\"")->implode(', ') ?: 'an element';
    }

    /**
     * Quote a string for PHP code.
     */
    protected static function string(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * Get the test's file name, such as PostAJobTest.php.
     */
    public static function fileName(Video $video): string
    {
        return Str::studly($video->slug).'Test.php';
    }
}
