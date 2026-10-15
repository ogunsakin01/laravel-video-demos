<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Ogunsakin01\VideoDemo\Ai\VoiceLines;
use Ogunsakin01\VideoDemo\Models\Video;
use Ogunsakin01\VideoDemo\Support\Language;

/**
 * The language codes a video is made in, and how the model is told about it.
 */
class LanguageTest extends TestCase
{
    public function test_codes_are_tidied_and_named(): void
    {
        $this->assertSame(['fr-FR', 'pt-BR', 'en', 'zh-Hant-TW', 'nb', null, null], array_map(Language::normalize(...), ['fr_FR', 'PT-br', ' EN ', 'zh-hant-tw', 'no', 'English', '']));
        $this->assertSame(['pt', 'en', ''], [Language::base('pt-BR'), Language::base('en'), Language::base(null)]);
        $this->assertSame(['French', 'Portuguese (Brazil)', 'Unknown'], [Language::name('fr'), Language::name('pt_BR'), Language::name('?')]);
        $this->assertTrue(Language::same('fr-FR', 'fr-CA'));
        $this->assertFalse(Language::same('fr', 'en'));
        $this->assertFalse(Language::same(null, null));
    }

    public function test_the_app_language_comes_from_its_locale_and_is_offered(): void
    {
        config(['app.locale' => 'pt_BR']);
        $this->assertSame('pt-BR', Language::app());
        $this->assertSame('Portuguese', Language::options()['pt']);

        config(['app.locale' => 'gsw']);
        $this->assertArrayHasKey('gsw', Language::options());
    }

    public function test_a_video_is_made_in_the_chosen_language_then_the_pages_then_the_app(): void
    {
        config(['app.locale' => 'en']);
        $video = new Video;
        $this->assertSame('en', $video->languageCode());

        $video->page_language = 'fr-FR';
        $this->assertSame('fr-FR', $video->languageCode());
        $this->assertStringContainsString('Language: French (France), code fr-FR. Write the title, every caption, card and chapter, and every say line in French (France), the language of the app\'s pages.', VoiceLines::language($video));
        $this->assertStringContainsString('not as a translation from English', VoiceLines::language($video));

        $video->language = 'es';
        $this->assertSame('es', $video->languageCode());
        $this->assertStringContainsString('in Spanish, as the developer chose, even though the app\'s pages are in French (France).', VoiceLines::language($video));

        $video->language = 'en';
        $this->assertStringNotContainsString('translation', VoiceLines::language($video));
    }
}
