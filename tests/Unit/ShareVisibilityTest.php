<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\Dashboard\Model as DashboardModel;
use Piwik\Plugins\FeinwerkDashboardSharing\API;
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;
use Piwik\Plugins\FeinwerkDashboardSharing\ShareAccess;
use Piwik\Plugins\FeinwerkDashboardSharing\SharingService;

/**
 * Shares of owners who have no website in common with the current user are neither listed nor subscribable, whether
 * they were shared with everyone or with the user's login. DAO, service and models are mocked.
 *
 * @group FeinwerkDashboardSharing
 */
class ShareVisibilityTest extends TestCase
{
    /** @var SharingService|\PHPUnit\Framework\MockObject\MockObject */
    private $service;

    public function test_availableShares_leaveOutOwnersWithoutACommonWebsite()
    {
        $names = array_column($this->api()->getAvailableShares(), 'name');

        $this->assertSame(array('From bob'), $names);
    }

    public function test_subscribing_toAShareOfAnOwnerWithoutACommonWebsite_isRejected()
    {
        $api = $this->api();
        $this->service->expects($this->never())->method('subscribe');
        $this->expectExceptionMessage('Share not available');

        $api->subscribeToShare(2);
    }

    public function test_subscribing_toAShareOfAnOwnerWithACommonWebsite_works()
    {
        $api = $this->api();
        $this->service->expects($this->once())->method('subscribe')->with(1, 'alice')->willReturn(5);

        $this->assertSame(5, $api->subscribeToShare(1));
    }

    public function test_copyOfAnOwnerWithoutACommonWebsite_isNoLongerSynced()
    {
        $api = $this->api();
        $this->service->expects($this->never())->method('syncLink');

        $this->assertSame(array('synced' => false), $api->syncLinkedDashboard(9));
    }

    public function test_copyOfAnOwnerWithACommonWebsite_isSynced()
    {
        $api = $this->api();
        $this->service->expects($this->once())->method('syncLink')->with('alice', 8)->willReturn(true);

        $this->assertSame(array('synced' => true), $api->syncLinkedDashboard(8));
    }

    private function api(): API
    {
        $sharing = $this->createMock(Sharing::class);
        $sharing->method('getSharesAvailableTo')->with('alice')->willReturn(array(
            array('idshare' => '1', 'owner_login' => 'bob', 'iddashboard' => '4', 'name' => 'From bob', 'share_to_all' => '1'),
            array('idshare' => '2', 'owner_login' => 'eve', 'iddashboard' => '2', 'name' => 'From eve', 'share_to_all' => '1'),
        ));
        $sharing->method('isSubscribed')->willReturn(false);
        // alice's copies: dashboard 8 mirrors bob's share 1, dashboard 9 mirrors eve's share 2
        $sharing->method('getLink')->willReturnCallback(function ($login, $id) {
            return $login === 'alice' && in_array($id, array(8, 9), true) ? array('idshare' => $id === 8 ? '1' : '2') : false;
        });
        $sharing->method('getShare')->willReturnCallback(function ($idShare) {
            return array('idshare' => $idShare, 'owner_login' => (string) $idShare === '1' ? 'bob' : 'eve', 'iddashboard' => '4');
        });

        $dashboards = $this->createMock(DashboardModel::class);
        $dashboards->method('getLayoutForUser')->willReturn(array(array('layout' => '{}')));

        $access = $this->createMock(ShareAccess::class);
        $access->method('isVisibleToCurrentUser')->willReturnCallback(function ($owner) {
            return $owner === 'bob';
        });

        $this->service = $this->createMock(SharingService::class);

        return new class ($sharing, $this->service, $dashboards, $access) extends API {
            protected function checkAndGetLogin(): string
            {
                return 'alice';
            }
        };
    }
}
