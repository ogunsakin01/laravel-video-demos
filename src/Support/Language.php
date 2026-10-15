<?php

namespace Ogunsakin01\VideoDemo\Support;

use Locale;

/**
 * The language a video is written and spoken in, as a code such as "fr" or
 * "pt-BR". By default it is the language of the app's pages: the lang
 * attribute the recorder finds on them, or the app's locale.
 */
final class Language
{
    /**
     * The languages offered on the forms, code => name.
     */
    public const NAMES = [
        'ar' => 'Arabic', 'bn' => 'Bengali', 'bg' => 'Bulgarian', 'ca' => 'Catalan', 'zh' => 'Chinese',
        'hr' => 'Croatian', 'cs' => 'Czech', 'da' => 'Danish', 'nl' => 'Dutch', 'en' => 'English',
        'et' => 'Estonian', 'fil' => 'Filipino', 'fi' => 'Finnish', 'fr' => 'French', 'de' => 'German',
        'el' => 'Greek', 'ha' => 'Hausa', 'he' => 'Hebrew', 'hi' => 'Hindi', 'hu' => 'Hungarian',
        'ig' => 'Igbo', 'id' => 'Indonesian', 'it' => 'Italian', 'ja' => 'Japanese', 'ko' => 'Korean',
        'lv' => 'Latvian', 'lt' => 'Lithuanian', 'ms' => 'Malay', 'nb' => 'Norwegian', 'fa' => 'Persian',
        'pl' => 'Polish', 'pt' => 'Portuguese', 'ro' => 'Romanian', 'ru' => 'Russian', 'sr' => 'Serbian',
        'sk' => 'Slovak', 'sl' => 'Slovenian', 'es' => 'Spanish', 'sw' => 'Swahili', 'sv' => 'Swedish',
        'ta' => 'Tamil', 'th' => 'Thai', 'tr' => 'Turkish', 'uk' => 'Ukrainian', 'ur' => 'Urdu',
        'vi' => 'Vietnamese', 'yo' => 'Yoruba',
    ];

    /**
     * Older or other codes for the same language.
     */
    protected const ALIASES = ['no' => 'nb', 'nn' => 'nb', 'iw' => 'he', 'in' => 'id', 'tl' => 'fil', 'cmn' => 'zh'];

    /**
     * Tidy a language code: "fr_FR" becomes "fr-FR", "EN" becomes "en".
     * Null when it isn't a language code.
     */
    public static function normalize(?string $code): ?string
    {
        $parts = explode('-', str_replace('_', '-', trim((string) $code)));
        if (! preg_match('/^[a-z]{2,3}$/i', $parts[0])) {
            return null;
        }

        $base = strtolower($parts[0]);
        $out = [self::ALIASES[$base] ?? $base];
        foreach (array_slice($parts, 1) as $part) {
            if (! preg_match('/^[a-z0-9]{2,8}$/i', $part)) {
                break;
            }
            // A region is upper case (pt-BR), a script title case (zh-Hant).
            $out[] = strlen($part) === 4 ? ucfirst(strtolower($part)) : (strlen($part) <= 3 ? strtoupper($part) : $part);
        }

        return implode('-', $out);
    }

    /**
     * Get a code's language without its region: "pt-BR" is "pt".
     */
    public static function base(?string $code): string
    {
        return explode('-', (string) self::normalize($code))[0];
    }

    /**
     * Get the app's language, from its locale.
     */
    public static function app(): string
    {
        return self::normalize((string) config('app.locale')) ?? 'en';
    }

    /**
     * Get a language's name in English, such as "French" or "Portuguese (Brazil)".
     */
    public static function name(?string $code): string
    {
        $code = self::normalize($code);
        if ($code === null) {
            return 'Unknown';
        }

        if (extension_loaded('intl') && is_string($name = Locale::getDisplayName($code, 'en')) && $name !== $code) {
            return $name;
        }

        $base = self::base($code);
        $region = substr($code, strlen($base) + 1);

        return (self::NAMES[$base] ?? $code).($region !== '' && isset(self::NAMES[$base]) ? " ($region)" : '');
    }

    /**
     * Get a language's name in that language, such as "Français", for a
     * list viewers pick their language from. Its English name without intl.
     */
    public static function native(?string $code): string
    {
        $code = self::normalize($code);
        if ($code !== null && extension_loaded('intl') && is_string($name = Locale::getDisplayName($code, $code)) && $name !== $code) {
            return mb_convert_case(mb_substr($name, 0, 1), MB_CASE_UPPER).mb_substr($name, 1);
        }

        return self::name($code);
    }

    /**
     * Determine if two codes are the same language, whatever their regions.
     */
    public static function same(?string $a, ?string $b): bool
    {
        return self::base($a) !== '' && self::base($a) === self::base($b);
    }

    /**
     * Get the languages to choose from, code => name, with the app's own
     * language among them.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = self::NAMES;
        if (! isset($options[self::app()]) && ! isset($options[self::base(self::app())])) {
            $options[self::app()] = self::name(self::app());
        }
        asort($options);

        return $options;
    }
}
