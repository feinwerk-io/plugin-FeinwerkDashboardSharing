<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\FeinwerkDashboardSharing;

/**
 * Matomo renders its bar at the top (website, date, segment, Manage Dashboard) with Template.nextToCalendar; the plugin
 * puts a slot there, after Matomo's own segment selector, that the script fills with the share buttons.
 *
 * @group FeinwerkDashboardSharing
 */
class TopControlSlotTest extends TestCase
{
    private function plugin(): FeinwerkDashboardSharing
    {
        return (new \ReflectionClass(FeinwerkDashboardSharing::class))->newInstanceWithoutConstructor();
    }

    public function test_hooksIntoTheTopBarAfterMatomosOwnControls(): void
    {
        $events = $this->plugin()->registerEvents();

        $this->assertSame(array('function' => 'renderTopControlSlot', 'after' => true), $events['Template.nextToCalendar'] ?? null);
    }

    public function test_appendsAnEmptySlotToOtherPluginsOutput(): void
    {
        $out = '<div class="other"></div>';

        $this->plugin()->renderTopControlSlot($out);

        $this->assertSame('<div class="other"></div><div id="fwsh-topcontrol-slot" class="fwsh-topControlSlot"></div>', $out);
    }
}
