<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Ogunsakin01\VideoDemo\VideoDemo;

/**
 * The guideline and skill Laravel Boost gives AI agents in apps that use the
 * package: written by hand, so this keeps them from naming commands, options,
 * methods or styles the package doesn't have.
 */
class BoostTest extends TestCase
{
    /**
     * Get the text of the guideline and the skill.
     *
     * @return array<string, string>
     */
    protected function files(): array
    {
        $root = __DIR__.'/../resources/boost';

        return [
            'guideline' => File::get("$root/guidelines/core.blade.php"),
            'skill' => File::get("$root/skills/laravel-video-demos/SKILL.md"),
        ];
    }

    public function test_the_skill_has_what_boost_needs(): void
    {
        $skill = $this->files()['skill'];

        $this->assertMatchesRegularExpression('/\A---\nname: laravel-video-demos\ndescription: .{40,}\n---\n/', $skill);
        $this->assertStringContainsString('`laravel-video-demos` skill', $this->files()['guideline']);
    }

    public function test_every_command_and_option_named_exists(): void
    {
        $commands = Artisan::all();

        foreach ($this->files() as $file => $text) {
            preg_match_all('/video-demo:([a-z-]+)((?:[ \t]+(?:"[^"]*"|[^\s`#|]+))*)/', $text, $uses, PREG_SET_ORDER);
            foreach ($uses as [, $name, $args]) {
                $this->assertArrayHasKey("video-demo:$name", $commands, "The $file names video-demo:$name, which doesn't exist.");
                $definition = $commands["video-demo:$name"]->getDefinition();
                preg_match_all('/--([a-z-]+)/', $args, $options);
                foreach ($options[1] as $option) {
                    $this->assertTrue($definition->hasOption($option), "The $file uses video-demo:$name --$option, which doesn't exist.");
                }
            }
        }
    }

    public function test_every_method_component_and_style_named_exists(): void
    {
        foreach ($this->files() as $file => $text) {
            preg_match_all('/VideoDemo::([a-zA-Z]+)\(/', $text, $methods);
            foreach (array_unique($methods[1]) as $method) {
                $this->assertTrue(method_exists(VideoDemo::class, $method), "The $file calls VideoDemo::$method(), which doesn't exist.");
            }

            preg_match_all('/<x-video-demo::([a-z-]+)/', $text, $components);
            foreach (array_unique($components[1]) as $component) {
                $this->assertFileExists(__DIR__."/../resources/views/components/$component.blade.php", "The $file uses <x-video-demo::$component>, which doesn't exist.");
            }
        }

        preg_match('/Styles: ((?:`[a-z]+`(?:, | or |, or )?)+)/', $this->files()['skill'], $styles);
        preg_match_all('/`([a-z]+)`/', $styles[1] ?? '', $keys);
        $this->assertNotEmpty($keys[1]);
        $this->assertEqualsCanonicalizing(array_keys(config('video-demo.styles')), $keys[1], 'The skill should list every built-in style.');
    }
}
