<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Integration;

use Piwik\API\Request;
use Piwik\Piwik;
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
    use SharingFixture;

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

        $this->createWebsites(3);
        $this->createUser('bob', array(1));   // shares website 1 with alice
        $this->createUser('eve', array(3));   // no website in common with alice
    }

    public function test_sharesOfOwnersWithoutACommonWebsite_areNeitherListedNorSubscribable()
    {
        $dashboards = new DashboardModel();
        $sharing = new Sharing();
        $fromBob = $sharing->createShare('bob', (int) $dashboards->createNewDashboardForUser('bob', 'B', '{}'), 'From bob', true, [], '2026-10-15 10:00:00');
        $eveDash = (int) $dashboards->createNewDashboardForUser('eve', 'E', '{}');
        $eveToAll = $sharing->createShare('eve', $eveDash, 'From eve to all', true, [], '2026-10-15 10:00:00');
        $eveToAlice = $sharing->createShare('eve', $eveDash, 'From eve to alice', false, ['alice'], '2026-10-15 10:00:00');

        $this->assertSame([(int) $fromBob], array_map('intval', array_column($this->api->getAvailableShares(), 'idshare')));

        foreach ([$eveToAll, $eveToAlice] as $idShare) {
            try {
                $this->api->subscribeToShare($idShare);
                $this->fail('a share of an owner without a common website must not be subscribable');
            } catch (\Exception $e) {
                $this->assertSame('Share not available', $e->getMessage());
            }
        }
        $this->assertSame([], $dashboards->getAllDashboardsForUser('alice'));
    }

    public function test_superUser_seesSharesOfEveryOwner()
    {
        FakeAccess::$superUser = true;
        $eveDash = (int) (new DashboardModel())->createNewDashboardForUser('eve', 'E', '{}');
        $idShare = (new Sharing())->createShare('eve', $eveDash, 'From eve', true, [], '2026-10-15 10:00:00');

        $this->assertSame([(int) $idShare], array_map('intval', array_column($this->api->getAvailableShares(), 'idshare')));
    }

    public function test_subscribe_copiesTheOwnersLayoutWithoutRequestChangingParameters()
    {
        $layout = json_encode(['config' => ['layout' => '50-50'], 'columns' => [[[
            'uniqueId' => 'widgetReferrersgetReferrerType', 'isHidden' => false,
            'parameters' => ['module' => 'Referrers', 'action' => 'getReferrerType', 'viewDataTable' => 'graphPie', 'idSite' => '3', 'token_auth' => 'x'],
        ]]]]);
        $dashboards = new DashboardModel();
        $bobDash = (int) $dashboards->createNewDashboardForUser('bob', 'B', $layout);
        $idShare = (new Sharing())->createShare('bob', $bobDash, 'Shared', true, [], '2026-10-15 10:00:00');

        $copy = (int) $this->api->subscribeToShare($idShare);

        $copied = json_decode($dashboards->getLayoutForUser('alice', $copy)[0]['layout'], true);
        $this->assertSame('50-50', $copied['config']['layout']);
        $this->assertSame(
            ['module' => 'Referrers', 'action' => 'getReferrerType', 'viewDataTable' => 'graphPie'],
            $copied['columns'][0][0]['parameters']
        );
    }

    public function test_httpApi_shareName_isStoredAsEntered()
    {
        $idDashboard = (int) (new DashboardModel())->createNewDashboardForUser('alice', 'Source', '{}');

        $idShare = (int) Request::processRequest('FeinwerkDashboardSharing.shareDashboard', array(
            'idDashboard' => (string) $idDashboard,
            'name'        => 'Tom & Jerry <KPIs>',
            'shareToAll'  => '1',
        ));

        $this->assertSame('Tom & Jerry <KPIs>', (new Sharing())->getShare($idShare)['name']);
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

    public function test_subscribeToShare_keepsTheSubscribersOwnDefaultDashboard()
    {
        $dashboards = new DashboardModel();
        $bobDash = (int) $dashboards->createNewDashboardForUser('bob', 'Bob dashboard', '{}');
        $idShare = (new Sharing())->createShare('bob', $bobDash, 'Shared', true, [], '2026-10-15 10:00:00');

        // alice never changed her dashboard: she only has Matomo's built-in default (id 1, not stored)
        $this->assertSame([], $dashboards->getAllDashboardsForUser('alice'));

        $copy = (int) $this->api->subscribeToShare($idShare);

        $this->assertSame(2, $copy, 'the copy gets an id of its own');
        $names = array_column($dashboards->getAllDashboardsForUser('alice'), 'name', 'iddashboard');
        $this->assertSame(Piwik::translate('Dashboard_Dashboard'), $names[1] ?? null, 'the default dashboard is stored as id 1');
        $this->assertSame((new \Piwik\Plugins\Dashboard\Dashboard())->getDefaultLayout(), $dashboards->getLayoutForUser('alice', 1)[0]['layout']);
        $this->assertSame(['linked' => false], $this->api->getLinkInfo(1));

        $this->api->unsubscribe($copy);
        $this->assertSame([1], array_map('intval', array_column($dashboards->getAllDashboardsForUser('alice'), 'iddashboard')), 'unsubscribing keeps the default dashboard');
    }

    public function test_unsubscribe_ofACopyAtId1FromAnEarlierVersion_givesBackTheDefaultDashboard()
    {
        $dashboards = new DashboardModel();
        $bobDash = (int) $dashboards->createNewDashboardForUser('bob', 'Bob dashboard', '{}');
        $idShare = (new Sharing())->createShare('bob', $bobDash, 'Shared', true, [], '2026-10-15 10:00:00');

        // what 1.0.2 stored for a subscriber without a stored dashboard: the copy took id 1; she created id 2 later
        $this->assertSame(1, (int) $dashboards->createNewDashboardForUser('alice', 'Shared', '{"columns":[]}'));
        (new Sharing())->createLink('alice', 1, $idShare);
        $this->assertSame(2, (int) $dashboards->createNewDashboardForUser('alice', 'Mine', '{}'));

        $this->api->unsubscribe(1);

        $names = array_column($dashboards->getAllDashboardsForUser('alice'), 'name', 'iddashboard');
        $this->assertSame([1, 2], array_map('intval', array_keys($names)), 'id 1 is kept, the other dashboard untouched');
        $this->assertSame(Piwik::translate('Dashboard_Dashboard'), $names[1]);
        $this->assertSame((new \Piwik\Plugins\Dashboard\Dashboard())->getDefaultLayout(), $dashboards->getLayoutForUser('alice', 1)[0]['layout']);
        $this->assertSame(['linked' => false], $this->api->getLinkInfo(1));
        $this->assertSame(0, (new Sharing())->getSubscriberCount($idShare));
    }

    public function test_httpApi_paddedShareNameAndTargets_areStoredTrimmed()
    {
        $idDashboard = (int) (new DashboardModel())->createNewDashboardForUser('alice', 'Source', '{}');

        // Through the HTTP API request parsing, which no longer trims values on Matomo 6.
        $idShare = (int) Request::processRequest('FeinwerkDashboardSharing.shareDashboard', array(
            'idDashboard'  => (string) $idDashboard,
            'name'         => '  Team KPIs  ',
            'targetLogins' => ' bob , carol ',
            'shareToAll'   => '0',
        ));

        $sharing = new Sharing();
        $this->assertSame('Team KPIs', $sharing->getShare($idShare)['name']);
        $targets = $sharing->getTargets($idShare);
        sort($targets);
        $this->assertSame(array('bob', 'carol'), $targets);
    }

    public function provideContainerConfig()
    {
        return array(
            'Piwik\Access' => new FakeAccess(),
        );
    }
}