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
use Piwik\Plugins\FeinwerkDashboardSharing\tests\Integration\TablesBeforeTheUpdate;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group FeinwerkDashboardSharing
 */
class SharingTest extends IntegrationTestCase
{
    use TablesBeforeTheUpdate;

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

    /**
     * "Anna" and "anna" as two different Matomo users. Before the update the plugin's tables may not tell them apart
     * (Matomo's user table may use another collation than the plugin's tables; a case-insensitive collation stands in
     * for every collation that treats two logins as equal); after it they compare exactly.
     */
    private function daoWithTwoUsers(): Sharing
    {
        return new Sharing(static function (string $login): string {
            return in_array($login, ['Anna', 'anna'], true) ? $login : '';
        });
    }

    /** The two states a customer's tables can be in: before the update to the binary login collation, and after it. */
    public function tableStates(): array
    {
        return ['before the update' => [true], 'after the update' => [false]];
    }

    private function tablesAsIn(bool $beforeTheUpdate): void
    {
        if ($beforeTheUpdate) {
            $this->giveTheTablesACaseInsensitiveCollation();
        }
    }

    public function test_install_givesEveryLoginColumnTheBinaryCollation()
    {
        foreach (Sharing::loginColumns() as $table => $column) {
            $this->assertNull($this->dao->exactLoginColumnType($table, $column), "$table.$column: nothing left for the update");
            $collation = (string) Db::fetchOne(
                'SELECT `COLLATION_NAME` FROM `information_schema`.`COLUMNS`
                  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = ?',
                [Common::prefixTable($table), $column]
            );
            $this->assertStringEndsWith('_bin', $collation, "$table.$column");
        }
    }

    public function test_theUpdate_givesExistingTablesTheBinaryCollation_once_andKeepsEveryRow()
    {
        $this->giveTheTablesACaseInsensitiveCollation();
        $dao = $this->daoWithTwoUsers();
        $id = $dao->createShare('alice', 5, 'for Anna', false, ['ANNA', 'bob'], '2026-06-16 10:00:00');
        $dao->createLink('Anna', 3, $id);

        $update = $this->loginCollationUpdate();
        $updater = $this->createMock(\Piwik\Updater::class);
        $migrations = $update->getMigrations($updater);
        $this->assertCount(3, $migrations, 'one per table');
        foreach ($migrations as $migration) {
            $migration->exec();
        }

        foreach (Sharing::loginColumns() as $table => $column) {
            $this->assertNull($dao->exactLoginColumnType($table, $column), "$table.$column");
        }
        $this->assertSame(['ANNA', 'bob'], $dao->getTargets($id), 'targets keep the case the owner typed');
        $this->assertSame($id, (int) $dao->getLink('Anna', 3)['idshare']);
        // "anna" now gets her own key on the same dashboard id
        $other = $dao->createShare('alice', 6, 'other', true, [], '2026-06-16 10:00:00');
        $dao->createLink('anna', 3, $other);
        $this->assertSame($other, (int) $dao->getLink('anna', 3)['idshare']);
        $this->assertSame($id, (int) $dao->getLink('Anna', 3)['idshare']);
        $this->assertSame([], $update->getMigrations($updater), 'a second run has nothing to do');
    }

    /** this branch's update with the login collation (its file name is the branch's own version) */
    private function loginCollationUpdate()
    {
        $files = array_values(array_filter(glob(__DIR__ . '/../../../Updates/*.php'), function ($file) {
            return strpos((string) file_get_contents($file), 'loginColumns') !== false;
        }));
        $this->assertCount(1, $files);
        require_once $files[0];
        $class = 'Piwik\\Plugins\\FeinwerkDashboardSharing\\Updates_' . strtolower(str_replace(['-', '.'], '_', basename($files[0], '.php')));

        return new $class(\Piwik\Container\StaticContainer::get(\Piwik\Updater\Migration\Factory::class));
    }

    public function test_afterTheUpdate_twoLoginsEachKeepTheirSubscriptionOnOneKey()
    {
        $dao = $this->daoWithTwoUsers();
        $id = $dao->createShare('alice', 5, 'public', true, [], '2026-06-16 10:00:00');
        $other = $dao->createShare('alice', 6, 'other', true, [], '2026-06-16 10:00:00');
        $dao->createLink('Anna', 3, $id);
        $dao->createLink('anna', 3, $other);

        $this->assertSame($id, (int) $dao->getLink('Anna', 3)['idshare']);
        $this->assertSame($other, (int) $dao->getLink('anna', 3)['idshare']);
        $this->assertSame([['iddashboard' => 3, 'owner' => 'alice']], $dao->getSubscribedDashboards('anna'));

        $dao->deleteLink('anna', 3);
        $this->assertFalse($dao->getLink('anna', 3));
        $this->assertNotFalse($dao->getLink('Anna', 3), 'deleting anna\'s link keeps Anna\'s');
    }

    public function test_beforeTheUpdate_aSubscriptionOfAnotherLogin_isNeitherReadNorOverwritten()
    {
        $this->giveTheTablesACaseInsensitiveCollation();
        $dao = $this->daoWithTwoUsers();
        $id = $dao->createShare('alice', 5, 'public', true, [], '2026-06-16 10:00:00');
        $dao->createLink('Anna', 3, $id);

        $this->assertFalse($dao->getLink('anna', 3), 'anna must not sync Anna\'s copy into her own dashboard 3');
        $this->assertFalse($dao->getLinkForShare($id, 'anna'));
        $this->assertFalse($dao->isSubscribed($id, 'anna'));
        $this->assertSame([], $dao->getSubscribedDashboards('anna'));

        $other = $dao->createShare('alice', 6, 'other', true, [], '2026-06-16 10:00:00');
        $refused = null;
        try {
            $dao->createLink('anna', 3, $other);
        } catch (\Exception $e) {
            $refused = $e;
        }
        $this->assertNotNull($refused, 'the key (anna, 3) belongs to Anna');
        $this->assertStringContainsString('another user', $refused->getMessage());
        $this->assertSame(409, $refused->getCode(), 'the API answers with HTTP 409');
        $this->assertSame($id, (int) $dao->getLink('Anna', 3)['idshare'], 'Anna\'s subscription is unchanged');

        $dao->createLink('Anna', 3, $other);
        $this->assertSame($other, (int) $dao->getLink('Anna', 3)['idshare'], 'the own key is updated');

        $dao->deleteLink('anna', 3);
        $this->assertNotFalse($dao->getLink('Anna', 3), 'deleting anna\'s link keeps Anna\'s');
    }

    /** @dataProvider tableStates */
    public function test_sharesOfAnotherOwner_areNotMine_butAvailable(bool $beforeTheUpdate)
    {
        $this->tablesAsIn($beforeTheUpdate);
        $dao = $this->daoWithTwoUsers();
        $id = $dao->createShare('Anna', 5, 'by Anna', true, [], '2026-06-16 10:00:00');

        $this->assertSame([], $dao->getSharesByOwner('anna'), 'anna must not list (or prune) Anna\'s shares');
        $this->assertSame([(int) $id], array_map('intval', array_column($dao->getSharesAvailableTo('anna'), 'idshare')));
        $this->assertSame([], $dao->getSharesAvailableTo('Anna'), 'never the owner\'s own share');
    }

    /** @dataProvider tableStates */
    public function test_aTargetOfAnotherUser_isNotOffered(bool $beforeTheUpdate)
    {
        $this->tablesAsIn($beforeTheUpdate);
        $dao = $this->daoWithTwoUsers();
        $id = $dao->createShare('alice', 5, 'for Anna', false, ['Anna'], '2026-06-16 10:00:00');

        $this->assertSame([], $dao->getSharesAvailableTo('anna'));
        $this->assertSame([(int) $id], array_map('intval', array_column($dao->getSharesAvailableTo('Anna'), 'idshare')));
    }

    /** @dataProvider tableStates */
    public function test_aTargetTypedInAnotherCase_isOfferedToTheUserMatomoResolvesItTo(bool $beforeTheUpdate)
    {
        $this->tablesAsIn($beforeTheUpdate);
        $dao = new Sharing(static function (string $login): string {
            return strtolower($login) === 'anna' ? 'anna' : '';
        });
        $id = $dao->createShare('alice', 5, 'for Anna', false, ['ANNA'], '2026-06-16 10:00:00');

        $this->assertSame([(int) $id], array_map('intval', array_column($dao->getSharesAvailableTo('anna'), 'idshare')));
        $this->assertSame(['ANNA'], $dao->getTargets($id), 'the target stays as the owner typed it');
    }

    /** @dataProvider tableStates */
    public function test_deletingAUser_keepsTheRowsOfAnotherLogin(bool $beforeTheUpdate)
    {
        $this->tablesAsIn($beforeTheUpdate);
        $dao = $this->daoWithTwoUsers();
        $owned = $dao->createShare('Anna', 5, 'by Anna', true, [], '2026-06-16 10:00:00');
        $offered = $dao->createShare('alice', 6, 'for Anna', false, ['Anna'], '2026-06-16 10:00:00');
        $dao->createLink('Anna', 3, $owned);

        $dao->deleteAllForUser('anna');

        $this->assertNotFalse($dao->getShare($owned));
        $this->assertSame(['Anna'], $dao->getTargets($offered));
        $this->assertNotFalse($dao->getLink('Anna', 3));

        $dao->deleteAllForUser('Anna');
        $this->assertFalse($dao->getShare($owned));
        $this->assertSame([], $dao->getTargets($offered));
        $this->assertFalse($dao->getLink('Anna', 3));
    }

    /**
     * Matomo deletes "anna" when a super user asks for "ANNA", and posts the event with "ANNA" after the user is gone.
     *
     * @dataProvider tableStates
     */
    public function test_deletingAUserNamedInAnotherSpelling_removesTheirRows_andKeepsAnotherUsers(bool $beforeTheUpdate)
    {
        $this->tablesAsIn($beforeTheUpdate);
        $dao = new Sharing(static function (string $login): string {
            return $login === 'Anna' ? 'Anna' : ''; // anna is deleted, Anna still exists
        });
        $owned = $dao->createShare('anna', 5, 'by anna', true, [], '2026-06-16 10:00:00');
        $offered = $dao->createShare('alice', 6, 'for anna', false, ['anna', 'bob'], '2026-06-16 10:00:00');
        $dao->createLink('anna', 3, $offered);
        $annasOwn = $dao->createShare('Anna', 7, 'by Anna', true, [], '2026-06-16 10:00:00');
        $dao->createLink('Anna', 4, $offered);

        $dao->deleteAllForUser('ANNA');

        $this->assertFalse($dao->getShare($owned), 'share of the deleted user');
        $this->assertSame(['bob'], $dao->getTargets($offered), 'target of the deleted user');
        $this->assertFalse($dao->getLink('anna', 3), 'subscription of the deleted user');
        $this->assertNotFalse($dao->getShare($annasOwn), 'another existing user keeps their share');
        $this->assertNotFalse($dao->getLink('Anna', 4), 'and their subscription');
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
