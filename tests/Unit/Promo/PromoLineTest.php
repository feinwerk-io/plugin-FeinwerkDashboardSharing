<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit\Promo;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\Promo\PromoLine;

/**
 * The messages of the Feinwerk promotion kit and the tip line: who sees them, which message, where the link leads, how
 * they are switched off. PHP 7.4 syntax (the plugin's 5.x line).
 *
 * @group Plugins
 * @group FeinwerkDashboardSharing
 */
class PromoLineTest extends TestCase
{
    public function test_on_by_default_and_every_spelling_of_off_switches_it_off(): void
    {
        self::assertTrue(PromoLine::enabledBy([]));
        self::assertTrue(PromoLine::enabledBy(['promotions' => '1']));
        foreach (['0', '', 'off', 'no', 'false', ' Off ', false, null] as $off) {
            self::assertFalse(PromoLine::enabledBy(['promotions' => $off]), var_export($off, true));
        }
    }

    /** Switch 2: [FeinwerkMarketplaceConnector] promotions = 0 turns it off for all Feinwerk plugins, also without the connector. */
    public function test_the_connectors_config_switches_it_off_for_all_plugins(): void
    {
        self::assertFalse(PromoLine::enabledBy([], ['promotions' => '0']));
        self::assertFalse(PromoLine::enabledBy([], ['promotions' => false]));
        self::assertTrue(PromoLine::enabledBy([], ['other' => '0']));
        self::assertFalse(PromoLine::enabledBy(['promotions' => '0'], ['promotions' => '1']), 'the plugin\'s own switch comes first');
    }

    /** Switch 3: the connector's setting in Matomo's UI; a failing connector counts as on. */
    public function test_the_connectors_setting_in_the_ui(): void
    {
        $off = static function (): bool {
            return false;
        };
        $on = static function (): bool {
            return true;
        };
        $broken = static function (): bool {
            throw new \RuntimeException('connector broken');
        };
        self::assertFalse(PromoLine::enabledBy([], [], $off));
        self::assertTrue(PromoLine::enabledBy([], [], $on));
        self::assertTrue(PromoLine::enabledBy([], [], $broken));
        self::assertFalse(PromoLine::enabledBy([], ['promotions' => '0'], $on), 'the config switch wins');
        self::assertFalse(PromoLine::enabledBy(['promotions' => 'off'], [], $on));
    }

    /** Older connectors have no switch, and it is asked only while the connector is activated. */
    public function test_the_connector_switch_only_when_it_exists_and_the_connector_is_active(): void
    {
        $active = static function (): bool {
            return true;
        };
        $inactive = static function (): bool {
            return false;
        };
        $failing = static function (): bool {
            throw new \RuntimeException('no plugin manager');
        };
        self::assertNull(PromoLine::connectorSwitch('\\Piwik\\Plugins\\NoSuchPlugin\\Promotions', $active), 'no class');
        self::assertNull(PromoLine::connectorSwitch(PromotionsWithoutSwitch::class, $active), 'older connector');
        self::assertNull(PromoLine::connectorSwitch(PromotionsOff::class, $inactive), 'connector not activated');
        self::assertNull(PromoLine::connectorSwitch(PromotionsOff::class, $failing));
        $switch = PromoLine::connectorSwitch(PromotionsOff::class, $active);
        self::assertNotNull($switch);
        self::assertFalse(PromoLine::enabledBy([], [], $switch));
    }

    public function test_only_admins_and_super_users(): void
    {
        self::assertTrue(PromoLine::visibleTo(false, true));
        self::assertFalse(PromoLine::visibleTo(false, false), 'view access only');
        self::assertFalse(PromoLine::visibleTo(true, false), 'anonymous');
        self::assertFalse(PromoLine::visibleTo(true, true), 'anonymous, whatever the access says');
    }

    public function test_not_in_embedded_widgets(): void
    {
        self::assertTrue(PromoLine::isEmbedded('Widgetize', 0));
        self::assertTrue(PromoLine::isEmbedded('CoreHome', 1), 'disableLink=1');
        self::assertFalse(PromoLine::isEmbedded('CoreHome', 0));
        self::assertFalse(PromoLine::isEmbedded('Dashboard', 0));
    }

    public function test_a_product_that_is_installed_is_not_advertised(): void
    {
        $everything = static function (string $plugin): bool {
            return true;
        };
        self::assertSame(['campaigns', 'hosting'], PromoLine::candidates($everything, true), 'websites have no plugin to check');
        foreach (PromoLine::MESSAGES as $id => $message) {
            if ($message['product'] !== null) {
                $product = $message['product'];
                $installed = static function (string $plugin) use ($product): bool {
                    return $plugin === $product;
                };
                self::assertNotContains($id, PromoLine::candidates($installed, true));
            }
        }
    }

    public function test_conversions_api_only_for_websites_with_ecommerce(): void
    {
        $nothing = static function (string $plugin): bool {
            return false;
        };
        self::assertSame(['campaigns', 'hosting', 'sentinel'], PromoLine::candidates($nothing));
        self::assertSame(['campaigns', 'hosting', 'capi', 'sentinel'], PromoLine::candidates($nothing, true));
    }

    public function test_the_free_plugin_shows_one_message_per_day_in_turn(): void
    {
        self::assertNull(PromoLine::PREFERRED, 'free plugins prefer no message');
        self::assertSame('campaigns', PromoLine::choose(['campaigns', 'hosting', 'sentinel'], 9));
        self::assertSame('hosting', PromoLine::choose(['campaigns', 'hosting', 'sentinel'], 10));
        self::assertSame('sentinel', PromoLine::choose(['campaigns', 'hosting', 'sentinel'], 11));
        self::assertSame('hosting', PromoLine::choose([2 => 'hosting'], 7), 'keys do not matter');
        self::assertNull(PromoLine::choose([], 7));
    }

    public function test_link_in_the_users_language_with_only_the_campaign_parameters(): void
    {
        self::assertSame(
            'https://campaigns.feinwerk.io/de/matomo?mtm_campaign=plugin-promo&mtm_source=FeinwerkDashboardSharing&mtm_content=campaigns',
            PromoLine::url('campaigns', 'de')
        );
        self::assertStringStartsWith('https://campaigns.feinwerk.io/en/matomo?', PromoLine::url('campaigns', 'fr'), 'English for other languages');
        self::assertSame(
            'https://feinwerk.io/en/contact?service=hosting&mtm_campaign=plugin-promo&mtm_source=FeinwerkDashboardSharing&mtm_content=hosting',
            PromoLine::url('hosting', 'en'),
            'a landing page with its own query string'
        );
    }

    public function test_every_message_has_texts_a_page_in_both_languages_and_a_placement(): void
    {
        foreach (PromoLine::MESSAGES as $id => $message) {
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $id);
            foreach (['title', 'text', 'link'] as $field) {
                self::assertStringStartsWith('FeinwerkDashboardSharing_Promo', $message[$field]);
            }
            foreach (['de', 'en'] as $language) {
                self::assertStringStartsWith('https://', $message['urls'][$language]);
            }
            self::assertArrayHasKey('placement', $message);
            self::assertIsBool($message['ecommerce']);
        }
    }

    public function test_variables_of_the_templates(): void
    {
        $translate = static function (string $key): string {
            return '[' . $key . ']';
        };
        self::assertSame([
            'id' => 'campaigns',
            'placement' => 'line',
            'title' => '[FeinwerkDashboardSharing_PromoCampaignsTitle]',
            'text' => '[FeinwerkDashboardSharing_PromoCampaignsText]',
            'link' => '[FeinwerkDashboardSharing_PromoCampaignsLink]',
            'url' => PromoLine::url('campaigns', 'en'),
            'dismissDays' => 30,
        ], PromoLine::variables('campaigns', 'en', $translate));
        self::assertSame('adminHome', PromoLine::variables('hosting', 'de', $translate, 'adminHome')['placement']);
    }

    public function test_unknown_message_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PromoLine::variables('pricing', 'en', static function (string $key): string {
            return $key;
        });
    }

    /** Every text of every message exists in both languages, without en or em dashes (house rule). */
    public function test_texts_in_german_and_english(): void
    {
        $keys = ['FeinwerkDashboardSharing_PromoTip', 'FeinwerkDashboardSharing_PromoDismiss'];
        foreach (PromoLine::MESSAGES as $message) {
            $keys[] = $message['title'];
            $keys[] = $message['text'];
            $keys[] = $message['link'];
        }
        foreach (['en', 'de'] as $language) {
            $texts = json_decode((string) file_get_contents(__DIR__ . '/../../../lang/' . $language . '.json'), true);
            foreach ($keys as $key) {
                $text = $texts['FeinwerkDashboardSharing'][substr($key, strlen('FeinwerkDashboardSharing_'))] ?? '';
                self::assertNotSame('', $text, $language . ' ' . $key);
                self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $text, $language . ' ' . $key);
            }
        }
    }
}

/** A connector release with the UI setting switched off. */
class PromotionsOff
{
    public static function areEnabled(): bool
    {
        return false;
    }
}

/** A connector release before the UI setting. */
class PromotionsWithoutSwitch
{
}
