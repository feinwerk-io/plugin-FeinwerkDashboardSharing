<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Integration;

use Piwik\API\Request;
use Piwik\Plugins\Dashboard\Model as DashboardModel;
use Piwik\Plugins\FeinwerkDashboardSharing\API;
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * A dashboard removed with Matomo's own "Remove dashboard" (API Dashboard.removeDashboard) must not leave sharing rows
 * behind: Matomo reuses the highest dashboard id for the user's next dashboard, which would otherwise turn into a
 * read-only mirror of someone else's dashboard (subscriber side) or be served to the recipients of a share (owner side).
 *
 * @group FeinwerkDashboardSharing
 */
class DashboardRemovalTest extends IntegrationTestCase
{
    /** @var Sharing */
    private $sharing;
    /** @var DashboardModel */
    private $dashboards;

    public function setUp(): void
    {
        parent::setUp();
        (new Sharing())->install();
        $this->sharing = new Sharing();
        $this->dashboards = new DashboardModel();

        FakeAccess::$superUser = false;
        FakeAccess::$identity = 'alice';
        FakeAccess::$idSitesView = array(1);
    }

    public function test_removingASubscribedCopy_dropsTheSubscription()
    {
        $bobDash = (int) $this->dashboards->createNewDashboardForUser('bob', 'Bob dashboard', '{}');
        $idShare = $this->sharing->createShare('bob', $bobDash, 'Shared', true, [], '2026-10-14 10:00:00');
        $copy = API::getInstance()->subscribeToShare($idShare);

        Request::processRequest('Dashboard.removeDashboard', ['idDashboard' => $copy, 'login' => 'alice']);

        $this->assertFalse($this->sharing->getLink('alice', $copy));
        $this->assertSame(0, $this->sharing->getSubscriberCount($idShare));
        $available = array_map('intval', array_column(API::getInstance()->getAvailableShares(), 'idshare'));
        $this->assertSame([(int) $idShare], $available); // the share can be subscribed to again
    }

    public function test_aNewDashboardReusingTheRemovedId_isNotAMirror()
    {
        $bobDash = (int) $this->dashboards->createNewDashboardForUser('bob', 'Bob dashboard', '{}');
        $idShare = $this->sharing->createShare('bob', $bobDash, 'Shared', true, [], '2026-10-14 10:00:00');
        $copy = API::getInstance()->subscribeToShare($idShare);

        Request::processRequest('Dashboard.removeDashboard', ['idDashboard' => $copy, 'login' => 'alice']);
        $fresh = (int) $this->dashboards->createNewDashboardForUser('alice', 'My new dashboard', '{}');

        $this->assertSame($copy, $fresh); // Matomo hands out MAX(id) + 1 again
        $this->assertSame(['linked' => false], API::getInstance()->getLinkInfo($fresh));
        $this->assertSame(['synced' => false], API::getInstance()->syncLinkedDashboard($fresh));
    }

    public function test_removingTheSourceDashboard_dropsItsShares()
    {
        $source = (int) $this->dashboards->createNewDashboardForUser('alice', 'Source', '{}');
        $other = (int) $this->dashboards->createNewDashboardForUser('alice', 'Other', '{}');
        $gone = $this->sharing->createShare('alice', $source, 'Gone', true, [], '2026-10-14 10:00:00');
        $kept = $this->sharing->createShare('alice', $other, 'Kept', true, [], '2026-10-14 10:00:00');

        Request::processRequest('Dashboard.removeDashboard', ['idDashboard' => $source]); // login defaults to the current user

        $this->assertFalse($this->sharing->getShare($gone));
        $this->assertNotFalse($this->sharing->getShare($kept));
    }

    public function provideContainerConfig()
    {
        return array(
            'Piwik\Access' => new FakeAccess(),
        );
    }
}
