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
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;
use Piwik\Plugins\FeinwerkDashboardSharing\SharingService;

/**
 * A user who never changed their dashboard has only Matomo's built-in default (id 1, not stored); a new dashboard would
 * get id 1 and replace it. Before a subscription is created, that default must be stored, so the copy gets an id of its
 * own. The dashboard model is mocked (subscribe() itself needs the database and is covered by the integration tests).
 *
 * @group FeinwerkDashboardSharing
 */
class DefaultDashboardTest extends TestCase
{
    public function test_userWithoutStoredDashboards_getsTheDefaultDashboardStored()
    {
        $model = $this->createMock(DashboardModel::class);
        $model->method('getAllDashboardsForUser')->with('alice')->willReturn(array());
        $model->expects($this->once())->method('createNewDashboardForUser')
            ->with('alice', 'Dashboard', '[["default layout"]]')
            ->willReturn(1);

        $this->service($model)->storeDefaultDashboardIfNone('alice');
    }

    public function test_userWithStoredDashboards_keepsThem()
    {
        $model = $this->createMock(DashboardModel::class);
        $model->method('getAllDashboardsForUser')->willReturn(array(array('iddashboard' => 1, 'name' => 'Dashboard', 'layout' => '[]')));
        $model->expects($this->never())->method('createNewDashboardForUser');

        $this->service($model)->storeDefaultDashboardIfNone('alice');
    }

    /**
     * Versions up to 1.0.2 could store a subscription at id 1 (the user's main dashboard). Unsubscribing must give the user
     * Matomo's default dashboard back there instead of deleting id 1, which Matomo expects to exist.
     */
    public function test_unsubscribingACopyAtId1_resetsItToTheDefaultDashboard()
    {
        $model = $this->createMock(DashboardModel::class);
        $model->expects($this->never())->method('deleteDashboardForUser');
        $model->expects($this->once())->method('updateLayoutForUser')->with('alice', 1, '[["default layout"]]');
        $model->expects($this->once())->method('updateDashboardName')->with('alice', 1, 'Dashboard');

        $this->service($model, $this->linkedSharing())->unsubscribe('alice', 1);
    }

    public function test_unsubscribingACopyWithAnotherId_deletesIt()
    {
        $model = $this->createMock(DashboardModel::class);
        $model->expects($this->once())->method('deleteDashboardForUser')->with(2, 'alice');
        $model->expects($this->never())->method('updateLayoutForUser');
        $model->expects($this->never())->method('updateDashboardName');

        $this->service($model, $this->linkedSharing())->unsubscribe('alice', 2);
    }

    private function linkedSharing(): Sharing
    {
        $sharing = $this->createMock(Sharing::class);
        $sharing->method('getLink')->willReturn(array('idshare' => 7));
        $sharing->expects($this->once())->method('deleteLink');
        return $sharing;
    }

    private function service(DashboardModel $model, ?Sharing $sharing = null): SharingService
    {
        return new class ($sharing ?: $this->createMock(Sharing::class), $model) extends SharingService {
            protected function defaultDashboard()
            {
                return array('Dashboard', '[["default layout"]]');
            }
        };
    }
}
