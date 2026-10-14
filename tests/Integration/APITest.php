<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Integration;

use Piwik\Plugins\Dashboard\Model as DashboardModel;
use Piwik\Plugins\FeinwerkDashboardSharing\API;
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group FeinwerkDashboardSharing
 */
class APITest extends IntegrationTestCase
{
    /** @var API */
    private $api;

    public function setUp(): void
    {
        parent::setUp();
        (new Sharing())->install();
        $this->api = API::getInstance();

        FakeAccess::$superUser = false;
        FakeAccess::$identity = 'alice';
        FakeAccess::$idSitesView = array(1, 2);
    }

    public function test_getMyShares_prunesShareWhoseSourceDashboardIsGone()
    {
        $dashboards = new DashboardModel();
        $real = (int) $dashboards->createNewDashboardForUser('alice', 'Real dashboard', '{}');

        $sharing = new Sharing();
        $kept  = $sharing->createShare('alice', $real, 'kept', true, [], '2026-06-16 10:00:00');
        $stale = $sharing->createShare('alice', 99999, 'stale', true, [], '2026-06-16 10:00:00');

        $ids = array_map('intval', array_column($this->api->getMyShares(), 'idshare'));

        $this->assertSame([(int) $kept], $ids);          // only the share with a live dashboard is listed
        $this->assertNotFalse($sharing->getShare($kept));
        $this->assertFalse($sharing->getShare($stale));  // the dead share was pruned
    }

    public function test_subscribeToShare_isIdempotent()
    {
        $dashboards = new DashboardModel();
        $bobDash = (int) $dashboards->createNewDashboardForUser('bob', 'Bob dashboard', '{}');

        $sharing = new Sharing();
        $idShare = $sharing->createShare('bob', $bobDash, 'Shared', true, [], '2026-06-16 10:00:00');

        // alice (FakeAccess identity) subscribes twice: a retry must not create a second copy.
        $first  = $this->api->subscribeToShare($idShare);
        $second = $this->api->subscribeToShare($idShare);

        $this->assertSame($first, $second);
        $this->assertSame(1, $sharing->getSubscriberCount($idShare));
    }

    public function provideContainerConfig()
    {
        return array(
            'Piwik\Access' => new FakeAccess(),
        );
    }
}