<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit\Promo;

use Matomo\Cache\Transient;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\Promo\PromoLine;
use Piwik\Plugins\FeinwerkDashboardSharing\Promo\PromoPlacements;
use Piwik\Widget\WidgetConfig;

/**
 * The free placements of the Feinwerk promotion kit: which message goes where, the claim once per page across all
 * Feinwerk plugins, and the context box widget that only joins pages that exist. PHP 7.4 syntax (the 5.x line).
 *
 * @group Plugins
 * @group FeinwerkDashboardSharing
 */
class PromoPlacementsTest extends TestCase
{
    public function test_each_placement_shows_its_message(): void
    {
        $nothing = static function (string $plugin): bool {
            return false;
        };
        self::assertSame('hosting', PromoPlacements::messageFor(PromoPlacements::BANNER, $nothing));
        self::assertSame('campaigns', PromoPlacements::messageFor('campaigns', $nothing));
        self::assertSame('capi', PromoPlacements::messageFor('ecommerce', $nothing), 'the ecommerce page exists only with ecommerce');
        self::assertSame('sentinel', PromoPlacements::messageFor('goals', $nothing));
        self::assertNull(PromoPlacements::messageFor('line', $nothing), 'the tip line is not a placement of its own');
    }

    public function test_no_box_for_a_product_that_is_installed(): void
    {
        $sentinel = static function (string $plugin): bool {
            return $plugin === 'FeinwerkSentinel';
        };
        $capi = static function (string $plugin): bool {
            return $plugin === 'FeinwerkConversionsApi';
        };
        self::assertNull(PromoPlacements::messageFor('goals', $sentinel));
        self::assertSame('campaigns', PromoPlacements::messageFor('campaigns', $sentinel));
        self::assertNull(PromoPlacements::messageFor('ecommerce', $capi));
    }

    /** Every message belongs to a placement this kit fills, and every placement has a message. */
    public function test_messages_and_placements_match(): void
    {
        $placements = array_merge([PromoPlacements::BANNER], array_keys(PromoPlacements::PAGES));
        $used = [];
        foreach (PromoLine::MESSAGES as $id => $message) {
            self::assertContains($message['placement'], $placements, $id);
            $used[] = $message['placement'];
        }
        self::assertEqualsCanonicalizing($placements, $used);
    }

    /** Once per page across all Feinwerk plugins: the first claims, the same plugin may ask again, another may not. */
    public function test_the_first_plugin_claims_a_placement_for_the_request(): void
    {
        $cache = new Transient();
        self::assertTrue(PromoPlacements::claim('campaigns', 'FeinwerkDashboardSharing', $cache));
        self::assertTrue(PromoPlacements::claim('campaigns', 'FeinwerkDashboardSharing', $cache), 'the widget list is built again');
        self::assertFalse(PromoPlacements::claim('campaigns', 'FeinwerkSentinel', $cache));
        self::assertTrue(PromoPlacements::claim('goals', 'FeinwerkSentinel', $cache), 'placements are claimed one by one');
        self::assertFalse(PromoPlacements::claim('goals', 'FeinwerkDashboardSharing', $cache));
        self::assertSame('FeinwerkDashboardSharing', $cache->fetch('fwpromo.claimed.campaigns'), 'the shared key of all Feinwerk plugins');
    }

    public function test_the_box_is_not_offered_to_api_calls_with_a_token(): void
    {
        self::assertTrue(PromoPlacements::isApiCallWithoutSession('API', 0), 'a script or a Widgetize embed');
        self::assertFalse(PromoPlacements::isApiCallWithoutSession('API', 1), 'Matomo\'s own interface');
        self::assertFalse(PromoPlacements::isApiCallWithoutSession('CoreHome', 0));
    }

    /** A box joins only a page that has widgets of its own, so it never creates a page (Ecommerce without ecommerce). */
    public function test_pages_with_widgets_ignore_promotion_boxes(): void
    {
        $report = new WidgetConfig();
        $report->setCategoryId('Referrers_Referrers');
        $report->setSubcategoryId('Referrers_Campaigns');
        $report->setModule('Referrers');
        $report->setAction('getCampaigns');

        $otherPluginsBox = PromoPlacements::boxConfig('goals', 'sentinel', 'Tip from Feinwerk');
        $otherPluginsBox->setModule('FeinwerkSentinel');

        self::assertSame(['Referrers_Referrers/Referrers_Campaigns' => true], PromoPlacements::pagesWithWidgets([$report, $otherPluginsBox]));
        self::assertSame([], PromoPlacements::pagesWithWidgets([]));
    }

    public function test_the_box_widget(): void
    {
        $config = PromoPlacements::boxConfig('ecommerce', 'capi', 'Tip from Feinwerk');

        self::assertSame('Goals_Ecommerce', $config->getCategoryId());
        self::assertSame('General_Overview', $config->getSubcategoryId());
        self::assertSame(
            ['module' => 'FeinwerkDashboardSharing', 'action' => 'promoBox', 'fwpromo' => 'capi'],
            $config->getParameters()
        );
        self::assertSame('Tip from Feinwerk', $config->getName());
        self::assertTrue($config->isWide(), 'a full row');
        self::assertFalse($config->isWidgetizeable(), 'not for dashboards or Widgetize');
        self::assertSame(PromoPlacements::BOX_ORDER, $config->getOrder(), 'after Matomo\'s own widgets');
    }

    /** The pages exist under these ids from Matomo 5.0 to 6.x (Marketing Campaigns Reporting uses the campaigns page). */
    public function test_report_pages(): void
    {
        self::assertSame([
            'campaigns' => ['Referrers_Referrers', 'Referrers_Campaigns'],
            'ecommerce' => ['Goals_Ecommerce', 'General_Overview'],
            'goals' => ['Goals_Goals', 'General_Overview'],
        ], PromoPlacements::PAGES);
    }
}
