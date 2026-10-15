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
use Piwik\Plugins\FeinwerkDashboardSharing\SharingService;

/**
 * Matomo 6 no longer trims request values: shareDashboard() must store the share name and the target logins without
 * surrounding spaces, whether they come from the share dialog or from an HTTP API call. The DAO and the dashboard model
 * are mocked, so no database is needed.
 *
 * @group FeinwerkDashboardSharing
 */
class ShareDashboardTrimTest extends TestCase
{
    /** @var array|null the arguments createShare() received */
    private $created;

    public function test_shareName_isStoredWithoutSurroundingSpaces()
    {
        $this->api()->shareDashboard(4, '  Team KPIs  ', 'bob');

        $this->assertSame('Team KPIs', $this->created[2]);
    }

    public function test_shareNameOfOnlySpaces_getsTheDefaultName()
    {
        $this->api()->shareDashboard(4, '   ', 'bob');

        $this->assertSame('Shared dashboard', $this->created[2]);
    }

    public function test_commaSeparatedTargets_areTrimmedAndEmptyEntriesDropped()
    {
        $this->api()->shareDashboard(4, 'Team', ' bob , carol ,, ');

        $this->assertSame(array('bob', 'carol'), array_values($this->created[4]));
    }

    public function test_targetArray_isTrimmedAndEmptyEntriesDropped()
    {
        $this->api()->shareDashboard(4, 'Team', array(' bob ', 'carol ', '  '));

        $this->assertSame(array('bob', 'carol'), array_values($this->created[4]));
    }

    public function test_targetArrayOfOnlySpaces_isRejectedLikeNoTarget()
    {
        $this->expectExceptionMessage('A share needs at least one target user');

        $this->api()->shareDashboard(4, 'Team', array(' ', ''));
    }

    public function test_shareName_isCutToMatomosDashboardNameLength()
    {
        $this->api()->shareDashboard(4, str_repeat('ü', 150), 'bob');

        $this->assertSame(str_repeat('ü', 100), $this->created[2]);
    }

    public function test_shareToAll_isStoredWithTrimmedName()
    {
        $this->api()->shareDashboard(4, ' Everyone ', '', true);

        $this->assertSame(array('alice', 4, 'Everyone', true, array()), array_slice($this->created, 0, 5));
    }

    private function api(): API
    {
        $this->created = null;

        $sharing = $this->createMock(Sharing::class);
        $sharing->method('createShare')->willReturnCallback(function () {
            $this->created = func_get_args();
            return 1;
        });

        $dashboards = $this->createMock(DashboardModel::class);
        $dashboards->method('getLayoutForUser')->willReturn(array(array('layout' => '{}')));

        return new class ($sharing, $this->createMock(SharingService::class), $dashboards) extends API {
            protected function checkAndGetLogin(): string
            {
                return 'alice';
            }
        };
    }
}
