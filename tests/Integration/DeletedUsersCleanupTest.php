<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Integration;

use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;
use Piwik\Plugins\FeinwerkDashboardSharing\FeinwerkDashboardSharing;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * A user deleted while the plugin was inactive leaves shares, target rows and subscriptions behind. Activating the
 * plugin removes them, so a new user with the same login does not inherit what was meant for the old one.
 *
 * @group FeinwerkDashboardSharing
 */
class DeletedUsersCleanupTest extends IntegrationTestCase
{
    use SharingFixture;

    public function test_activation_removesRowsOfLoginsThatNoLongerExist()
    {
        $sharing = new Sharing();
        $sharing->install();
        $this->createWebsites(1);
        $this->createUser('bob', array(1));

        $ghostShare = $sharing->createShare('ghost', 4, 'Gone owner', true, [], '2026-10-15 10:00:00');
        $bobShare = $sharing->createShare('bob', 2, 'Kept', false, ['ghost', 'bob2'], '2026-10-15 10:00:00');
        $this->createUser('bob2', array(1));
        $sharing->createLink('ghost', 3, $bobShare);
        $sharing->createLink('bob2', 5, $bobShare);
        $onlyForGhost = $sharing->createShare('bob', 2, 'Only for ghost', false, ['ghost'], '2026-10-15 10:00:00');
        $bobToAll = $sharing->createShare('bob', 2, 'To all', true, [], '2026-10-15 10:00:00');

        (new FeinwerkDashboardSharing())->activate();

        $this->assertFalse($sharing->getShare($ghostShare), 'share of a deleted owner');
        $this->assertNotFalse($sharing->getShare($bobShare));
        $this->assertSame(['bob2'], $sharing->getTargets($bobShare), 'target row of a deleted login');
        $this->assertFalse($sharing->getLink('ghost', 3), 'subscription of a deleted login');
        $this->assertNotFalse($sharing->getLink('bob2', 5));
        $this->assertFalse($sharing->getShare($onlyForGhost), 'a share with logins whose last login is gone');
        $this->assertNotFalse($sharing->getShare($bobToAll), 'a share with everyone needs no target rows');
    }
}
