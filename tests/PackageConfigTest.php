<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Support\PackageConfig;
use Ogunsakin01\VideoDemo\VideoDemoServiceProvider;

/**
 * An app's own copy of the config, laid over the package's.
 */
class PackageConfigTest extends TestCase
{
    public function test_settings_added_since_the_apps_copy_reach_the_app(): void
    {
        // A copy from an older version, with one style of the app's own and
        // one built-in turned off, loaded before the package registers.
        config(['video-demo' => [
            'styles' => [
                'clean' => ['name' => 'Our walkthrough', 'direction' => 'Our words.'],
                'quick' => false,
            ],
            'library' => ['public' => ['middleware' => ['web', 'auth']]],
        ]]);
        $this->app->register(VideoDemoServiceProvider::class, force: true);

        // Styles the copy never listed are there, as the package has them.
        $this->assertSame('Promo reel', config('video-demo.styles.promo.name'));
        $this->assertSame('Spotlight', config('video-demo.styles.spotlight.name'));
        // The app's own settings win, entry by entry.
        $this->assertSame(['Our walkthrough', 'Our words.'], [config('video-demo.styles.clean.name'), config('video-demo.styles.clean.direction')]);
        $this->assertSame('light', config('video-demo.styles.clean.theme'));
        // False turns a built-in off.
        $this->assertArrayNotHasKey('quick', config('video-demo.styles'));
        // A plain list is the app's alone, not added to.
        $this->assertSame(['web', 'auth'], config('video-demo.library.public.middleware'));
        // And whole sections the copy lacks.
        $this->assertNotEmpty(config('video-demo.ai.models'));
    }

    public function test_merging_keeps_the_apps_values_and_adds_the_rest(): void
    {
        $merged = PackageConfig::merge(
            ['ai' => ['models' => ['a' => ['label' => 'A', 'input' => 1], 'b' => ['label' => 'B']]], 'list' => [1, 2], 'flag' => true],
            ['ai' => ['models' => ['a' => ['input' => 9], 'b' => false]], 'list' => [3], 'flag' => false],
        );

        $this->assertSame(['ai' => ['models' => ['a' => ['input' => 9, 'label' => 'A']]], 'list' => [3], 'flag' => false], $merged);
    }

    public function test_every_shipped_config_is_known_so_an_unedited_copy_can_be_told(): void
    {
        // Changing config/video-demo.php means adding its new fingerprint to RELEASED.
        $current = PackageConfig::fingerprint((string) file_get_contents(PackageConfig::path()));
        $this->assertArrayHasKey($current, PackageConfig::RELEASED, 'Add the config\'s new fingerprint to PackageConfig::RELEASED.');

        $file = sys_get_temp_dir().'/video-demo-config-'.uniqid().'.php';
        file_put_contents($file, str_replace("\n", "\r\n", (string) file_get_contents(PackageConfig::path())));
        $this->assertSame('current', PackageConfig::uneditedVersion($file));
        file_put_contents($file, file_get_contents(PackageConfig::path())."\n// Our change\n");
        $this->assertNull(PackageConfig::uneditedVersion($file));
        unlink($file);
    }
}
