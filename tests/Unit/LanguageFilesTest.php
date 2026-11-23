<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every plugin ships at least German, English, French, Spanish and Italian (Peter, 2026-11-19: "bitte alle Plugins
 * mindestens in französisch, spanisch, italienisch, deutsch, englisch ausliefern"): every language file has exactly the
 * sections and keys of en.json in their order, the same placeholders, no en or em dash and no URL.
 */
class LanguageFilesTest extends TestCase
{
    private const LANGUAGES = ['en', 'de', 'fr', 'es', 'it'];

    /** Texts whose translations may leave out a placeholder of the English one (none in this plugin). */
    private const OPTIONAL_PLACEHOLDERS = [];

    /** @return array<string, array<string, string>> sections of lang/<language>.json */
    private static function sections(string $language): array
    {
        $path = __DIR__ . '/../../lang/' . $language . '.json';
        self::assertFileExists($path, "lang/$language.json is missing");
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data, "lang/$language.json is no JSON object");

        return $data;
    }

    /** @return string[] */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%(?:\d+\$)?s/', $text, $m);
        $found = $m[0];
        sort($found);

        return $found;
    }

    public function test_every_language_has_the_sections_and_keys_of_english_in_their_order(): void
    {
        $english = self::sections('en');
        self::assertSame('FeinwerkDashboardSharing', array_keys($english)[0]);
        foreach (self::LANGUAGES as $language) {
            $texts = self::sections($language);
            self::assertSame(array_keys($english), array_keys($texts), "$language: sections");
            foreach ($english as $section => $keys) {
                self::assertSame(array_keys($keys), array_keys($texts[$section]), "$language: keys of $section and their order");
            }
        }
    }

    public function test_every_text_keeps_the_placeholders_of_english(): void
    {
        $english = self::sections('en');
        foreach (self::LANGUAGES as $language) {
            foreach (self::sections($language) as $section => $texts) {
                foreach ($texts as $key => $text) {
                    $found = self::placeholders($text);
                    $expected = self::placeholders($english[$section][$key]);
                    self::assertNotSame('', trim($text), "$language: $key is empty");
                    self::assertStringNotContainsString('\\$', $text, "$language: $key has an escaped placeholder");
                    if ($found !== $expected && isset(self::OPTIONAL_PLACEHOLDERS[$key])) {
                        self::assertSame(self::OPTIONAL_PLACEHOLDERS[$key], $found, "$language: $key");
                        continue;
                    }
                    self::assertSame($expected, $found, "$language: $key");
                }
            }
        }
    }

    public function test_no_text_has_a_dash_or_a_url(): void
    {
        foreach (self::LANGUAGES as $language) {
            foreach (self::sections($language) as $texts) {
                foreach ($texts as $key => $text) {
                    self::assertSame(0, preg_match('/[\x{2013}\x{2014}]/u', $text), "$language: $key has an en or em dash");
                    self::assertStringNotContainsString('http', $text, "$language: $key has a URL");
                }
            }
        }
    }
}
