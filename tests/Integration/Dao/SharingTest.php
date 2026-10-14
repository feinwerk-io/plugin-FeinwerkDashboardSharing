<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Integration\Dao;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group FeinwerkDashboardSharing
 */
class SharingTest extends IntegrationTestCase
{
    /** @var Sharing */
    private $dao;

    public function setUp(): void
    {
        parent::setUp();
        $this->dao = new Sharing();
        $this->dao->install();
    }

    public function test_createShare_withTargets_storesShareAndTargets()
    {
        $id = $this->dao->createShare('alice', 5, 'My share', false, ['bob', 'carol'], '2026-06-16 10:00:00');

        $share = $this->dao->getShare($id);
        $this->assertSame('alice', $share['owner_login']);
        $this->assertSame('My share', $share['name']);
        $this->assertSame(0, (int) $share['share_to_all']);
        $this->assertSame(['bob', 'carol'], $this->dao->getTargets($id));
    }

    public function test_availability_respectsTargetsAndShareToAll_andExcludesOwn()
    {
        $toAll  = $this->dao->createShare('alice', 5, 'public', true, [], '2026-06-16 10:00:00');
        $toBob  = $this->dao->createShare('alice', 6, 'for bob', false, ['bob'], '2026-06-16 10:00:00');
        $this->dao->createShare('alice', 7, 'for carol', false, ['carol'], '2026-06-16 10:00:00');

        $bobIds = array_map('intval', array_column($this->dao->getSharesAvailableTo('bob'), 'idshare'));
        sort($bobIds);
        $this->assertSame([(int) $toAll, (int) $toBob], $bobIds);

        // owner never sees their own shares as available
        $this->assertSame([], $this->dao->getSharesAvailableTo('alice'));
    }

    public function test_subscriptionLink_lifecycle()
    {
        $id = $this->dao->createShare('alice', 5, 'public', true, [], '2026-06-16 10:00:00');

        $this->dao->createLink('bob', 99, $id);
        $this->assertNotFalse($this->dao->getLink('bob', 99));
        $this->assertTrue($this->dao->isSubscribed($id, 'bob'));
        $this->assertSame(1, $this->dao->getSubscriberCount($id));

        $this->dao->deleteLink('bob', 99);
        $this->assertFalse($this->dao->getLink('bob', 99));
        $this->assertSame(0, $this->dao->getSubscriberCount($id));
    }

    public function test_getLinkForShare_returnsLinkOrFalse()
    {
        $id = $this->dao->createShare('alice', 5, 'public', true, [], '2026-06-16 10:00:00');

        $this->assertFalse($this->dao->getLinkForShare($id, 'bob'));

        $this->dao->createLink('bob', 99, $id);
        $link = $this->dao->getLinkForShare($id, 'bob');
        $this->assertSame(99, (int) $link['iddashboard']);
    }

    public function test_deleteShare_removesTargetsAndLinks()
    {
        $id = $this->dao->createShare('alice', 5, 'public', true, ['bob'], '2026-06-16 10:00:00');
        $this->dao->createLink('bob', 99, $id);

        $this->dao->deleteShare($id);

        $this->assertFalse($this->dao->getShare($id));
        $this->assertSame([], $this->dao->getTargets($id));
        $this->assertFalse($this->dao->getLink('bob', 99));
    }

    public function test_install_isIdempotentWhenTablesExist()
    {
        // setUp() already installed once; installing again must adopt the existing tables without error
        $this->dao->install();

        foreach (['shared_dashboard', 'shared_dashboard_target', 'shared_dashboard_link'] as $table) {
            $name = Common::prefixTable($table);
            $this->assertSame($name, Db::fetchOne(sprintf("SHOW TABLES LIKE '%s'", $name)));
        }
    }
}
