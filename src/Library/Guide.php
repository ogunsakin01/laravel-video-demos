<?php

namespace Ogunsakin01\VideoDemo\Library;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;

/**
 * A written, step-by-step guide made from a video's plan, at no AI cost, for
 * the library page under the video: for people who would rather read, for
 * screen readers, and for search engines, which index text and not video.
 *
 * Chapters become headings, the steps that do something read like "Click
 * Post a job" or "Fill in Job title and Location", and captions explain
 * what is shown. Opening pages, waiting, scrolling and title cards are left
 * out, and so is what was typed: it is sample data. The words around the
 * labels come from the package's translations (lang/vendor/video-demo), in
 * the video's language, falling back to English.
 */
final class Guide
{
    /**
     * Get the guide for a plan's steps: its sections, each with an optional
     * chapter title and its items. Empty when the plan does nothing a
     * person would do, such as a plan of captions only.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return list<array{title: ?string, items: list<array<string, mixed>>}>
     */
    public static function fromSteps(array $steps, string $device = 'desktop'): array
    {
        /** @var list<array{title: ?string, items: list<array<string, mixed>>}> $sections */
        $sections = [['title' => null, 'items' => []]];
        $current = 0;

        foreach ($steps as $step) {
            // Steps for other screens, such as a phone's menu button, aren't this version's.
            if (! empty($step['only_on']) && ! in_array($device, (array) $step['only_on'], true)) {
                continue;
            }
            if (! empty($step['chapter'])) {
                $sections[] = ['title' => (string) $step['chapter'], 'items' => []];
                $current = count($sections) - 1;
            }

            $item = self::item($step);
            if ($item === null) {
                continue;
            }

            $count = count($sections[$current]['items']);
            // Fields filled in one after another read as one step.
            if ($item['do'] === 'type' && $count > 0 && $sections[$current]['items'][$count - 1]['do'] === 'type') {
                $sections[$current]['items'][$count - 1]['what'][] = $item['what'][0];
            } else {
                $sections[$current]['items'][] = $item;
            }
        }

        $sections = array_values(array_filter($sections, fn ($s) => $s['items'] !== []));

        // Explanations alone, with nothing to do, aren't a guide.
        return collect($sections)->flatMap(fn ($s) => $s['items'])->contains(fn ($i) => $i['do'] !== 'note') ? $sections : [];
    }

    /**
     * Turn one step into a guide item, or null for a step that shows nothing to do.
     *
     * @return ?array{do: string, what?: list<string>, value?: string, title?: string, text?: string}
     */
    protected static function item(array $step): ?array
    {
        $action = (string) ($step['action'] ?? '');
        if ($action === 'caption') {
            $title = trim((string) ($step['title'] ?? ''));
            $text = trim((string) ($step['text'] ?? ''));

            return $title === '' && $text === '' ? null : ['do' => 'note', 'title' => $title, 'text' => $text];
        }

        $target = (array) ($step['target'] ?? []);
        $name = trim((string) ($target['name'] ?? '') ?: ($target['label'] ?? '') ?: ($target['text'] ?? '') ?: ($target['placeholder'] ?? ''));
        // An element found only by a CSS selector has no name a reader would recognise.
        if ($name === '' && $action !== 'press') {
            return null;
        }

        return match ($action) {
            'click' => ['do' => in_array($target['role'] ?? null, ['checkbox', 'switch', 'radio'], true) ? 'check' : 'click', 'what' => [$name]],
            'check' => ['do' => 'check', 'what' => [$name]],
            'type' => ['do' => 'type', 'what' => [$name]],
            'select' => ['do' => 'select', 'what' => [$name], 'value' => trim((string) ($step['value'] ?? ''))],
            'press' => trim((string) ($step['value'] ?? '')) === '' ? null : ['do' => 'press', 'what' => [trim((string) $step['value'])]],
            'hover' => ['do' => 'hover', 'what' => [$name]],
            default => null,
        };
    }

    /**
     * Get the locale to word the guide in: the video's language, or its base
     * language when only that has the words (pt for pt-BR), else the app's.
     */
    public static function locale(?string $language): ?string
    {
        if ($language === null || Lang::has('video-demo::guide.click', $language, false)) {
            return $language;
        }
        $base = strtolower(strtok($language, '-_') ?: $language);

        return Lang::has('video-demo::guide.click', $base, false) ? $base : $language;
    }

    /**
     * Split a section's items into numbered runs of steps and the notes
     * between them, for showing.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{steps?: list<array<string, mixed>>, note?: array<string, mixed>}>
     */
    public static function blocks(array $items): array
    {
        $blocks = [];
        foreach ($items as $item) {
            if ($item['do'] === 'note') {
                $blocks[] = ['note' => $item];
            } elseif ($blocks !== [] && isset($blocks[array_key_last($blocks)]['steps'])) {
                $blocks[array_key_last($blocks)]['steps'][] = $item;
            } else {
                $blocks[] = ['steps' => [$item]];
            }
        }

        return $blocks;
    }

    /**
     * Get an item's sentence as HTML, with the labels in bold, in the given
     * language. Everything from the plan is escaped.
     */
    public static function sentence(array $item, ?string $language = null): HtmlString
    {
        $language = self::locale($language);
        $bold = fn (string $text) => '<strong>'.e($text).'</strong>';
        $what = $item['what'] ?? [];
        $list = count($what) > 1
            ? implode(', ', array_map($bold, array_slice($what, 0, -1))).' '.e(__('video-demo::guide.and', [], $language)).' '.$bold((string) end($what))
            : $bold((string) ($what[0] ?? ''));

        if ($item['do'] === 'select' && ($item['value'] ?? '') === '') {
            $item['do'] = 'click';
        }
        // The words come from translations; the placeholders are put in after escaping them.
        $sentence = e(__("video-demo::guide.{$item['do']}", ['what' => '%what%', 'value' => '%value%'], $language));

        return new HtmlString(str_replace(['%what%', '%value%'], [$list, $bold((string) ($item['value'] ?? ''))], $sentence));
    }
}
