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
 * What reaches a subscriber's copy: the owner's layout only after it has been cleaned (see LayoutSanitizerTest), and a
 * dashboard name that fits Matomo's dashboard name column (100 characters). Models are mocked; subscribe() opens a DB
 * transaction and is covered by the integration tests.
 *
 * @group FeinwerkDashboardSharing
 */
class SharingServiceCopyTest extends TestCase
{
    public function test_sync_writesTheCleanedLayoutToTheCopy()
    {
        $sharing = $this->createMock(Sharing::class);
        $sharing->method('getLink')->willReturn(array('idshare' => 7));
        $sharing->method('getShare')->willReturn(array('owner_login' => 'bob', 'iddashboard' => 3));

        $owner = json_encode(array('config' => array('layout' => '50-50'), 'columns' => array(array(array(
            'uniqueId' => 'w1', 'isHidden' => false,
            'parameters' => array('module' => 'Referrers', 'action' => 'getReferrerType', 'idSite' => '2', 'token_auth' => 'x'),
        )))));
        $written = null;
        $model = $this->createMock(DashboardModel::class);
        $model->method('getLayoutForUser')->willReturn(array(array('layout' => $owner)));
        $model->expects($this->once())->method('updateLayoutForUser')->willReturnCallback(function ($login, $id, $layout) use (&$written) {
            $written = array($login, $id, json_decode($layout, true));
        });

        (new SharingService($sharing, $model))->syncLink('alice', 12);

        $this->assertSame('alice', $written[0]);
        $this->assertSame(12, $written[1]);
        $this->assertSame(array('module' => 'Referrers', 'action' => 'getReferrerType'), $written[2]['columns'][0][0]['parameters']);
    }

    public function test_copyName_staysWithinMatomosDashboardNameLength()
    {
        $long = str_repeat('ä', 100);
        $model = $this->createMock(DashboardModel::class);
        $model->method('getAllDashboardsForUser')->willReturn(array(array('name' => $long)));

        $name = $this->service($model)->name('alice', $long);

        $this->assertSame(str_repeat('ä', 96) . ' (2)', $name);
        $this->assertSame(100, mb_strlen($name));
    }

    public function test_tooLongCopyName_isShortened()
    {
        $model = $this->createMock(DashboardModel::class);
        $model->method('getAllDashboardsForUser')->willReturn(array());

        $this->assertSame(100, mb_strlen($this->service($model)->name('alice', str_repeat('x', 150))));
    }

    private function service(DashboardModel $model)
    {
        return new class ($this->createMock(Sharing::class), $model) extends SharingService {
            public function name($login, $desired)
            {
                return $this->uniqueName($login, $desired);
            }
        };
    }
}
