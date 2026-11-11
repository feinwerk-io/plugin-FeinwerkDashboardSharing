<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\API;
use Piwik\Plugins\FeinwerkDashboardSharing\FeinwerkDashboardSharing;

/**
 * Peter, 2026-11-11: Dashboard Sharing gets neither the Feinwerk promotions nor the feedback question (5.0.0 and
 * 6.0.0-beta.1 had both). Nothing hooks into Matomo's admin home or report pages, no promotion or feedback script,
 * style, text or API method is left.
 *
 * @group FeinwerkDashboardSharing
 */
class NoPromotionsTest extends TestCase
{
    private function plugin(): FeinwerkDashboardSharing
    {
        return (new \ReflectionClass(FeinwerkDashboardSharing::class))->newInstanceWithoutConstructor();
    }

    public function test_no_banner_on_the_admin_home_and_no_boxes_on_report_pages(): void
    {
        $events = $this->plugin()->registerEvents();
        $this->assertArrayNotHasKey('Template.adminHome', $events);
        $this->assertArrayNotHasKey('Widget.filterWidgets', $events);
    }

    public function test_only_the_share_dialog_script_and_stylesheet(): void
    {
        $js = [];
        $this->plugin()->getJsFiles($js);
        $this->assertSame(['plugins/FeinwerkDashboardSharing/javascripts/dashboardSharing.js'], $js);

        $css = [];
        $this->plugin()->getStylesheetFiles($css);
        $this->assertSame(['plugins/FeinwerkDashboardSharing/stylesheets/dashboardSharing.less'], $css);
    }

    public function test_no_feedback_api_method(): void
    {
        $this->assertFalse(method_exists(API::class, 'sendFeedback'));
    }

    public function test_no_promotion_or_feedback_texts(): void
    {
        foreach (['en', 'de'] as $language) {
            $texts = json_decode((string) file_get_contents(__DIR__ . '/../../lang/' . $language . '.json'), true);
            foreach (array_keys($texts['FeinwerkDashboardSharing']) as $key) {
                $this->assertDoesNotMatchRegularExpression('/^(Promo|Feedback)/', $key, $language . ' ' . $key);
            }
        }
    }
}
