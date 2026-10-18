<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Library\Guide;

/**
 * The written guide under each library video, made from its plan.
 */
class GuideTest extends TestCase
{
    protected function step(string $action, array $target = [], array $fields = []): array
    {
        return ['action' => $action, 'target' => $target ?: null, ...$fields];
    }

    public function test_steps_read_like_instructions_grouped_by_chapter(): void
    {
        $guide = Guide::fromSteps([
            $this->step('card', [], ['title' => 'Post a job']),
            $this->step('goto', [], ['url' => '/jobs', 'chapter' => 'Open the form']),
            $this->step('click', ['role' => 'link', 'name' => 'Post a job']),
            $this->step('caption', [], ['title' => 'The form', 'text' => 'Two fields are required.', 'chapter' => 'Fill it in']),
            $this->step('type', ['label' => 'Job title'], ['value' => 'Head of Support']),
            $this->step('type', ['placeholder' => 'City'], ['value' => 'Accra']),
            $this->step('select', ['label' => 'Contract'], ['value' => 'Full time']),
            $this->step('click', ['role' => 'checkbox', 'name' => 'Remote friendly']),
            $this->step('click', ['selector' => '#save']),
            $this->step('click', ['role' => 'button', 'name' => 'Open menu'], ['only_on' => ['mobile']]),
            $this->step('press', [], ['value' => 'Enter']),
            $this->step('wait', ['text' => 'Saved']),
        ]);

        // The card and the page opening are left out, so the guide starts at the first chapter.
        $this->assertSame(['Open the form', 'Fill it in'], array_column($guide, 'title'));
        $sentences = collect($guide)->flatMap(fn ($s) => $s['items'])->map(fn ($i) => $i['do'] === 'note' ? 'Note: '.$i['title'] : strip_tags((string) Guide::sentence($i)))->all();
        // Fields filled one after another are one step; what was typed isn't shown;
        // a step found only by CSS, and a phone-only step, are left out.
        $this->assertSame(['Click Post a job.', 'Note: The form', 'Fill in Job title and City.', 'Choose Full time in Contract.', 'Tick Remote friendly.', 'Press Enter.'], $sentences);
        $this->assertStringNotContainsString('Head of Support', json_encode($guide));
        $this->assertSame('Click Open menu.', strip_tags((string) Guide::sentence(Guide::fromSteps([$this->step('click', ['role' => 'button', 'name' => 'Open menu'], ['only_on' => ['mobile']])], 'mobile')[0]['items'][0])));
    }

    public function test_labels_are_escaped_and_words_follow_the_videos_language(): void
    {
        $item = Guide::fromSteps([$this->step('click', ['role' => 'button', 'name' => '<img src=x onerror=alert(1)>'])])[0]['items'][0];
        $this->assertSame('Click <strong>&lt;img src=x onerror=alert(1)&gt;</strong>.', (string) Guide::sentence($item));

        // The languages the package words, a region using its language's words, and one without, in English.
        $this->assertSame('Cliquez sur <strong>&lt;img src=x onerror=alert(1)&gt;</strong>.', (string) Guide::sentence($item, 'fr'));
        $this->assertSame('Clique em <strong>&lt;img src=x onerror=alert(1)&gt;</strong>.', (string) Guide::sentence($item, 'pt-BR'));
        $this->assertSame('Click <strong>&lt;img src=x onerror=alert(1)&gt;</strong>.', (string) Guide::sentence($item, 'sw'));
        $fill = Guide::fromSteps([['action' => 'type', 'target' => ['label' => 'Titel']], ['action' => 'type', 'target' => ['label' => 'Ort']]])[0]['items'][0];
        $this->assertSame('Füllen Sie <strong>Titel</strong> und <strong>Ort</strong> aus.', (string) Guide::sentence($fill, 'de'));
    }

    public function test_a_plan_with_nothing_to_do_has_no_guide(): void
    {
        $this->assertSame([], Guide::fromSteps([
            $this->step('card', [], ['title' => 'Welcome']),
            $this->step('caption', [], ['title' => 'Your dashboard', 'text' => 'Everything at a glance.']),
            $this->step('goto', [], ['url' => '/dashboard']),
        ]));
    }
}
