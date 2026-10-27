<?php

namespace Ogunsakin01\VideoDemo\Support;

/**
 * The package's config, and an app's own copy of it.
 *
 * Laravel takes a whole section, such as "styles", from an app's copy, so
 * an older copy would hide what the package added since. Instead the app's
 * copy is laid over the package's config: every setting it has wins, and
 * what it lacks comes from the package.
 */
class PackageConfig
{
    /**
     * Each version of the config the package has shipped, by its
     * fingerprint, to tell a copy nobody edited from one that was. Add the
     * new fingerprint whenever config/video-demo.php changes.
     */
    public const RELEASED = [
        '46ec60660e852fd78fc2198cc8030bd035939dfd' => 'v0.1.0',
        '482cf69601d8062ebd0d1845a6ebbb5c1f347d01' => 'v0.1.0',
        '81a3e1ef28b2e68d0ad53a48afca20ac53b0fcd2' => 'v0.3.0',
        '806a73070b039d6079b5aea8c879954d01aca1f8' => 'v0.4.0',
        'aa747dae3283ad1409c8d335d6eb85323c2555a0' => 'v0.5.0',
        '77895f49ccc3717fd284599f9e8dfc2761d39e21' => 'v0.5.1',
        '860630d91671e30e9bc1be3adb3a5942ed37a128' => 'v0.6.0',
        '0ba89a99d41b5f7b556d68896d3644c64dd3737e' => 'v0.7.0',
        '6000ef2f547674eb4aa60f4ba5d6f8d1636a5cdf' => 'v0.8.0',
        '84856909a9bed3c3930bbe63b351d834f6fd03aa' => 'v0.9.0',
        '964f1d8f2c2fce43be67c787a6bd9f2913860d14' => 'v0.9.1',
        'a90ee744ba182576992c3763f68179f8fc4f80b0' => 'v0.10.0',
        '640c4a5dcd5446004a4aab235316c85a36fe3a8d' => 'v0.11.0',
        '5395fc54a67632a7a513ba11cc749f05b057b0e4' => 'v0.12.0',
        '95b93264ce8fe92b555872fe1644f4a49fccbcf9' => 'v0.14.0',
        '62f6c6c240b45f402baa94255fd878d0d457767a' => 'v0.15.0',
        '3892068c18b47de942d8f2a4e893ff3ccdfc39d2' => 'v0.17.0',
        '30b70cd47e078cf560f339ef3374b2241e2c2b9c' => 'v0.17.5',
        '3f2f59a7a2ff40cedd0a1a2826b44d749c76e717' => 'v0.17.11',
        '971a49c899a231ccec5f82c98a0cca9fd897db65' => 'v0.18.0',
        '98b19d0fdb3d0e63ad72f3872c443236df9d2c30' => 'v0.18.1',
        'c17efec5e29cf775c6f287fc8764ca0b290e7dd7' => 'v0.18.2',
        'f07434eb42a698b8a9febdd7024626612773d644' => 'v1.0.0',
    ];

    /**
     * Named lists where an app can turn a built-in entry off with false,
     * such as 'promo' => false under styles.
     */
    protected const NAMED = ['styles', 'ai.models', 'devices'];

    /**
     * Get the package's own config.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return require static::path();
    }

    /**
     * Get the path of the package's own config file.
     */
    public static function path(): string
    {
        return dirname(__DIR__, 2).'/config/video-demo.php';
    }

    /**
     * Lay an app's settings over the package's: an app's setting wins,
     * settings the app lacks come from the package. Keyed sections (a
     * model's prices, a style) are merged entry by entry; plain lists (such
     * as middleware) are the app's alone. In a named list, false removes
     * the package's entry.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $app
     * @return array<string, mixed>
     */
    public static function merge(array $defaults, array $app, string $path = ''): array
    {
        foreach ($defaults as $key => $value) {
            $name = $path === '' ? (string) $key : "$path.$key";
            if (! array_key_exists($key, $app)) {
                $app[$key] = $value;
            } elseif (static::isMap($value) && static::isMap($app[$key])) {
                $app[$key] = static::merge($value, $app[$key], $name);
            }
        }

        if (in_array($path, self::NAMED, true)) {
            $app = array_filter($app, fn ($entry) => $entry !== false);
        }

        return $app;
    }

    /**
     * Get the version of the package's config that a copy is, unedited, or
     * null when it was edited (or isn't one).
     */
    public static function uneditedVersion(string $file): ?string
    {
        if (! is_file($file)) {
            return null;
        }
        $hash = static::fingerprint((string) file_get_contents($file));

        if ($hash === static::fingerprint((string) file_get_contents(static::path()))) {
            return 'current';
        }

        return self::RELEASED[$hash] ?? null;
    }

    /**
     * Get a config file's fingerprint, the same with any line endings.
     */
    public static function fingerprint(string $contents): string
    {
        return sha1(str_replace("\r\n", "\n", $contents));
    }

    /**
     * Determine if a value is a keyed array, not a plain list.
     */
    protected static function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }
}
