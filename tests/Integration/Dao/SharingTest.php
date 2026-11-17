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

    /**
     * "Anna" and "anna" as two different Matomo users that the plugin's tables cannot tell apart: Matomo's user table
     * may use another collation than the plugin's tables. The case-insensitive collation of the test database stands in
     * for every collation that treats two logins as equal.
     */
    private function daoWithTwoUsers(): Sharing
    {
        return new Sharing(static function (string $login): string {
            return in_array($login, ['Anna', 'anna'], true) ? $login : '';
        });
    }

    private function skipUnlessTheTablesIgnoreCase(): void
    {
        $id = $this->dao->createShare('Anna', 1, 'probe', true, [], '2026-06-16 10:00:00');
        $same = (int) Db::fetchOne(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `owner_login` = ?', Common::prefixTable('shared_dashboard')),
            ['anna']
        );
        $this->dao->deleteShare($id);
        if ($same === 0) {
            $this->markTestSkipped('the test database compares logins case-sensitively');
        }
    }

    public function test_aSubscriptionOfAnotherLogin_isNeitherReadNorOverwritten()
    {
        $this->skipUnlessTheTablesIgnoreCase();
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
        $this->assertSame($id, (int) $dao->getLink('Anna', 3)['idshare'], 'Anna\'s subscription is unchanged');

        $dao->createLink('Anna', 3, $other);
        $this->assertSame($other, (int) $dao->getLink('Anna', 3)['idshare'], 'the own key is updated');

        $dao->deleteLink('anna', 3);
        $this->assertNotFalse($dao->getLink('Anna', 3), 'deleting anna\'s link keeps Anna\'s');
    }

    public function test_sharesOfAnotherOwner_areNotMine_butAvailable()
    {
        $this->skipUnlessTheTablesIgnoreCase();
        $dao = $this->daoWithTwoUsers();
        $id = $dao->createShare('Anna', 5, 'by Anna', true, [], '2026-06-16 10:00:00');

        $this->assertSame([], $dao->getSharesByOwner('anna'), 'anna must not list (or prune) Anna\'s shares');
        $this->assertSame([(int) $id], array_map('intval', array_column($dao->getSharesAvailableTo('anna'), 'idshare')));
        $this->assertSame([], $dao->getSharesAvailableTo('Anna'), 'never the owner\'s own share');
    }

    public function test_aTargetOfAnotherUser_isNotOffered()
    {
        $this->skipUnlessTheTablesIgnoreCase();
        $dao = $this->daoWithTwoUsers();
        $id = $dao->createShare('alice', 5, 'for Anna', false, ['Anna'], '2026-06-16 10:00:00');

        $this->assertSame([], $dao->getSharesAvailableTo('anna'));
        $this->assertSame([(int) $id], array_map('intval', array_column($dao->getSharesAvailableTo('Anna'), 'idshare')));
    }

    public function test_aTargetTypedInAnotherCase_isOfferedToTheUserMatomoResolvesItTo()
    {
        $this->skipUnlessTheTablesIgnoreCase();
        $dao = new Sharing(static function (string $login): string {
            return strtolower($login) === 'anna' ? 'anna' : '';
        });
        $id = $dao->createShare('alice', 5, 'for Anna', false, ['ANNA'], '2026-06-16 10:00:00');

        $this->assertSame([(int) $id], array_map('intval', array_column($dao->getSharesAvailableTo('anna'), 'idshare')));
    }

    public function test_deletingAUser_keepsTheRowsOfAnotherLogin()
    {
        $this->skipUnlessTheTablesIgnoreCase();
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

    /** Matomo deletes "anna" when a super user asks for "ANNA", and posts the event with "ANNA" after the user is gone. */
    public function test_deletingAUserNamedInAnotherSpelling_removesTheirRows_andKeepsAnotherUsers()
    {
        $this->skipUnlessTheTablesIgnoreCase();
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
