<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\Dao;

use Piwik\Common;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Plugins\FeinwerkDashboardSharing\LoginMatch;
use Piwik\Plugins\UsersManager\Model as UsersModel;

/**
 * Registry of shared dashboards. A share makes one of the owner's dashboards importable by other
 * users — either by everyone (share_to_all) or by a list of target logins. Importing creates an
 * independent copy for the recipient (handled elsewhere); this Dao only tracks availability.
 *
 * Every row found by a login is checked again with an exact comparison (LoginMatch) before it is used or changed: the
 * plugin's tables compare logins with their collation, which can treat two different Matomo users as one.
 */
class Sharing
{
    private $shareTable = 'shared_dashboard';
    private $targetTable = 'shared_dashboard_target';
    private $linkTable = 'shared_dashboard_link';
    private $sharePrefixed;
    private $targetPrefixed;
    private $linkPrefixed;
    /** @var callable(string): string the stored login of the Matomo user a login resolves to, '' if none */
    private $canonicalLogin;

    /** @param callable(string): string|null $canonicalLogin test seam; default: Matomo's user lookup */
    public function __construct(?callable $canonicalLogin = null)
    {
        $this->sharePrefixed = Common::prefixTable($this->shareTable);
        $this->targetPrefixed = Common::prefixTable($this->targetTable);
        $this->linkPrefixed = Common::prefixTable($this->linkTable);
        $this->canonicalLogin = $canonicalLogin ?: static function (string $login): string {
            $user = (new UsersModel())->getUser($login);

            return is_array($user) && isset($user['login']) ? (string) $user['login'] : '';
        };
    }

    public function install()
    {
        DbHelper::createTable($this->shareTable, "
            `idshare` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `owner_login` VARCHAR(100) NOT NULL,
            `iddashboard` INT(10) UNSIGNED NOT NULL,
            `name` VARCHAR(150) NOT NULL DEFAULT '',
            `share_to_all` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `ts_created` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`idshare`),
            KEY `owner` (`owner_login`)");

        DbHelper::createTable($this->targetTable, "
            `idshare` INT(10) UNSIGNED NOT NULL,
            `target_login` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`idshare`, `target_login`),
            KEY `target` (`target_login`)");

        // A subscriber's local dashboard that mirrors a shared source (auto-synced on open).
        DbHelper::createTable($this->linkTable, "
            `subscriber_login` VARCHAR(100) NOT NULL,
            `iddashboard` INT(10) UNSIGNED NOT NULL,
            `idshare` INT(10) UNSIGNED NOT NULL,
            PRIMARY KEY (`subscriber_login`, `iddashboard`),
            KEY `idshare` (`idshare`)");
    }

    public function uninstall()
    {
        Db::query(sprintf('DROP TABLE IF EXISTS `%s`', $this->sharePrefixed));
        Db::query(sprintf('DROP TABLE IF EXISTS `%s`', $this->targetPrefixed));
        Db::query(sprintf('DROP TABLE IF EXISTS `%s`', $this->linkPrefixed));
    }

    // ---- subscriptions (links) --------------------------------------------------------------

    public function createLink($subscriberLogin, $idDashboard, $idShare)
    {
        // The key (subscriber, dashboard) compares the login with the table's collation, so it may be held by another
        // login. Update only the user's own row; otherwise a plain insert: a key that another login holds, or that a
        // parallel request took meanwhile, ends in a duplicate-key error (and the caller's rollback), never in an
        // overwrite. This holds under every isolation level.
        $rows = Db::fetchAll(
            sprintf('SELECT `subscriber_login` FROM `%s` WHERE `subscriber_login` = ? AND `iddashboard` = ?', $this->linkPrefixed),
            array($subscriberLogin, (int) $idDashboard)
        );
        $own = LoginMatch::rowsOf($rows, 'subscriber_login', (string) $subscriberLogin);
        if (count($rows) !== count($own)) {
            throw new \Exception('This dashboard is already linked for another user.');
        }
        if ($own) {
            // the key matches exactly one row under the collation, and it is the user's own
            Db::query(
                sprintf('UPDATE `%s` SET `idshare` = ? WHERE `subscriber_login` = ? AND `iddashboard` = ?', $this->linkPrefixed),
                array((int) $idShare, $subscriberLogin, (int) $idDashboard)
            );
            return;
        }
        Db::query(
            sprintf('INSERT INTO `%s` (`subscriber_login`, `iddashboard`, `idshare`) VALUES (?, ?, ?)', $this->linkPrefixed),
            array($subscriberLogin, (int) $idDashboard, (int) $idShare)
        );
    }

    /** @return array|false the link row for a subscriber's dashboard, or false if not linked */
    public function getLink($subscriberLogin, $idDashboard)
    {
        $rows = Db::fetchAll(
            sprintf('SELECT * FROM `%s` WHERE `subscriber_login` = ? AND `iddashboard` = ?', $this->linkPrefixed),
            array($subscriberLogin, (int) $idDashboard)
        );
        $own = LoginMatch::rowsOf($rows, 'subscriber_login', (string) $subscriberLogin);

        return $own ? $own[0] : false;
    }

    public function isSubscribed($idShare, $subscriberLogin)
    {
        return $this->getLinkForShare($idShare, $subscriberLogin) !== false;
    }

    /** @return array|false the subscriber's link row for a given share, or false if not subscribed */
    public function getLinkForShare($idShare, $subscriberLogin)
    {
        $rows = Db::fetchAll(
            sprintf('SELECT * FROM `%s` WHERE `idshare` = ? AND `subscriber_login` = ?', $this->linkPrefixed),
            array((int) $idShare, $subscriberLogin)
        );
        $own = LoginMatch::rowsOf($rows, 'subscriber_login', (string) $subscriberLogin);

        return $own ? $own[0] : false;
    }

    public function getSubscriberCount($idShare)
    {
        return (int) Db::fetchOne(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `idshare` = ?', $this->linkPrefixed),
            array((int) $idShare)
        );
    }

    public function deleteLink($subscriberLogin, $idDashboard)
    {
        if ($this->getLink($subscriberLogin, $idDashboard) === false) {
            return; // the key matches only another user's row (or none): leave it
        }
        Db::query(
            sprintf('DELETE FROM `%s` WHERE `subscriber_login` = ? AND `iddashboard` = ?', $this->linkPrefixed),
            array($subscriberLogin, (int) $idDashboard)
        );
    }

    public function deleteLinksForShare($idShare)
    {
        Db::query(sprintf('DELETE FROM `%s` WHERE `idshare` = ?', $this->linkPrefixed), array((int) $idShare));
    }

    /**
     * Dashboards the given user has subscribed to (linked), with the source owner.
     * @return array<array{iddashboard:int, owner:string}>
     */
    public function getSubscribedDashboards($subscriberLogin)
    {
        $rows = Db::fetchAll(
            sprintf(
                'SELECT l.`iddashboard`, l.`subscriber_login`, s.`owner_login`
                 FROM `%s` l JOIN `%s` s ON l.`idshare` = s.`idshare`
                 WHERE l.`subscriber_login` = ?',
                $this->linkPrefixed,
                $this->sharePrefixed
            ),
            array($subscriberLogin)
        );
        $result = array();
        foreach (LoginMatch::rowsOf($rows, 'subscriber_login', (string) $subscriberLogin) as $row) {
            $result[] = array('iddashboard' => (int) $row['iddashboard'], 'owner' => $row['owner_login']);
        }
        return $result;
    }

    /**
     * @param string[] $targetLogins
     * @return int new idshare
     */
    public function createShare($ownerLogin, $idDashboard, $name, $shareToAll, array $targetLogins, $now)
    {
        $db = Db::get();
        $db->beginTransaction();
        try {
            $db->insert($this->sharePrefixed, array(
                'owner_login'  => $ownerLogin,
                'iddashboard'  => (int) $idDashboard,
                'name'         => $name,
                'share_to_all' => $shareToAll ? 1 : 0,
                'ts_created'   => $now,
            ));
            $idShare = (int) $db->lastInsertId();

            foreach (array_unique($targetLogins) as $login) {
                if ($login === '') {
                    continue;
                }
                $db->insert($this->targetPrefixed, array('idshare' => $idShare, 'target_login' => $login));
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return $idShare;
    }

    public function getShare($idShare)
    {
        return Db::fetchRow(
            sprintf('SELECT * FROM `%s` WHERE `idshare` = ?', $this->sharePrefixed),
            array((int) $idShare)
        );
    }

    public function getTargets($idShare)
    {
        $rows = Db::fetchAll(
            sprintf('SELECT `target_login` FROM `%s` WHERE `idshare` = ?', $this->targetPrefixed),
            array((int) $idShare)
        );
        return array_map(function ($r) { return $r['target_login']; }, $rows);
    }

    public function getSharesByOwner($ownerLogin)
    {
        $rows = Db::fetchAll(
            sprintf('SELECT * FROM `%s` WHERE `owner_login` = ? ORDER BY `idshare` DESC', $this->sharePrefixed),
            array($ownerLogin)
        );

        return LoginMatch::rowsOf($rows, 'owner_login', (string) $ownerLogin);
    }

    /**
     * Shares the given user may import: shared to everyone, or explicitly to them; never their own. The owner and the
     * targets are compared exactly; a target typed in another case still counts when Matomo resolves it to this user.
     */
    public function getSharesAvailableTo($login)
    {
        $login = (string) $login;
        $mine = array();
        $targets = Db::fetchAll(
            sprintf('SELECT `idshare`, `target_login` FROM `%s` WHERE `target_login` = ?', $this->targetPrefixed),
            array($login)
        );
        foreach ($targets as $row) {
            if (LoginMatch::targetIs((string) $row['target_login'], $login, $this->canonicalLogin)) {
                $mine[(int) $row['idshare']] = true;
            }
        }

        $shares = Db::fetchAll(
            sprintf(
                'SELECT s.* FROM `%s` s
                 WHERE s.`share_to_all` = 1
                    OR EXISTS (SELECT 1 FROM `%s` t WHERE t.`idshare` = s.`idshare` AND t.`target_login` = ?)
                 ORDER BY s.`idshare` DESC',
                $this->sharePrefixed,
                $this->targetPrefixed
            ),
            array($login)
        );

        return array_values(array_filter($shares, static function ($share) use ($login, $mine) {
            if (LoginMatch::isSame((string) $share['owner_login'], $login)) {
                return false;
            }

            return (int) $share['share_to_all'] === 1 || isset($mine[(int) $share['idshare']]);
        }));
    }

    public function deleteShare($idShare)
    {
        $db = Db::get();
        $db->beginTransaction();
        try {
            Db::query(sprintf('DELETE FROM `%s` WHERE `idshare` = ?', $this->sharePrefixed), array((int) $idShare));
            Db::query(sprintf('DELETE FROM `%s` WHERE `idshare` = ?', $this->targetPrefixed), array((int) $idShare));
            $this->deleteLinksForShare($idShare);
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Remove shares, target rows and subscriptions of logins that no longer exist, then shares with a list of logins
     * whose last login is gone. UsersManager.deleteUser cleans up while the plugin is active; a user deleted while it
     * was inactive would otherwise leave rows behind, and a new user with the same login would see the shares meant
     * for the old one. Each login is looked up on its own (no JOIN): core's user table may have another collation.
     */
    public function deleteRowsOfDeletedUsers()
    {
        // UNION ALL and an exact de-duplication here: a plain UNION would fold "Bob" and "bob" into one value under the
        // table's collation, and the rows of the other spelling would never be checked.
        $rows = Db::fetchAll(sprintf(
            'SELECT `owner_login` AS `login` FROM `%s` UNION ALL SELECT `target_login` FROM `%s` UNION ALL SELECT `subscriber_login` FROM `%s`',
            $this->sharePrefixed,
            $this->targetPrefixed,
            $this->linkPrefixed
        ));
        $logins = array();
        foreach ($rows as $row) {
            $logins[(string) $row['login']] = true;
        }
        $userTable = Common::prefixTable('user');
        foreach (array_keys($logins) as $login) {
            if (!Db::fetchOne(sprintf('SELECT 1 FROM `%s` WHERE `login` = ?', $userTable), array((string) $login))) {
                $this->deleteAllForUser((string) $login);
            }
        }

        $withoutTargets = Db::fetchAll(sprintf(
            'SELECT s.`idshare` FROM `%s` s WHERE s.`share_to_all` = 0
             AND NOT EXISTS (SELECT 1 FROM `%s` t WHERE t.`idshare` = s.`idshare`)',
            $this->sharePrefixed,
            $this->targetPrefixed
        ));
        foreach ($withoutTargets as $row) {
            $this->deleteShare($row['idshare']);
        }
    }

    /**
     * Remove shares owned by, target rows referencing, and subscriptions of a deleted user. $login is the login as the
     * deletion named it (Matomo passes the caller's spelling, after the user row is gone). A row found by the table's
     * collation goes when LoginMatch::goesWithDeletedUser() says it belongs to that user or to no user; rows of another
     * existing user stay.
     */
    public function deleteAllForUser($login)
    {
        $login = (string) $login;
        $resolved = array();
        $canonical = function (string $candidate) use (&$resolved): string {
            if (!array_key_exists($candidate, $resolved)) {
                $resolved[$candidate] = (string) ($this->canonicalLogin)($candidate);
            }

            return $resolved[$candidate];
        };
        $stored = $canonical($login);
        $goes = static function (string $rowLogin) use ($login, $stored, $canonical): bool {
            return LoginMatch::goesWithDeletedUser($rowLogin, $login, $stored, $canonical);
        };

        $owned = Db::fetchAll(
            sprintf('SELECT `idshare`, `owner_login` FROM `%s` WHERE `owner_login` = ?', $this->sharePrefixed),
            array($login)
        );
        foreach ($owned as $row) {
            if ($goes((string) $row['owner_login'])) {
                $this->deleteShare((int) $row['idshare']);
            }
        }

        // Under the table's collation each share has at most one target row and each dashboard at most one link row
        // that matches this login (they are primary keys), so deleting by login plus the ids checked here removes
        // exactly the rows that belong to this user.
        $targets = Db::fetchAll(
            sprintf('SELECT `idshare`, `target_login` FROM `%s` WHERE `target_login` = ?', $this->targetPrefixed),
            array($login)
        );
        $idShares = array();
        foreach ($targets as $row) {
            if ($goes((string) $row['target_login'])) {
                $idShares[] = (int) $row['idshare'];
            }
        }
        if ($idShares) {
            Db::query(
                sprintf('DELETE FROM `%s` WHERE `target_login` = ? AND `idshare` IN (%s)', $this->targetPrefixed,
                    implode(',', array_fill(0, count($idShares), '?'))),
                array_merge(array($login), $idShares)
            );
        }

        $links = Db::fetchAll(
            sprintf('SELECT `subscriber_login`, `iddashboard` FROM `%s` WHERE `subscriber_login` = ?', $this->linkPrefixed),
            array($login)
        );
        $idDashboards = array();
        foreach ($links as $row) {
            if ($goes((string) $row['subscriber_login'])) {
                $idDashboards[] = (int) $row['iddashboard'];
            }
        }
        if ($idDashboards) {
            Db::query(
                sprintf('DELETE FROM `%s` WHERE `subscriber_login` = ? AND `iddashboard` IN (%s)', $this->linkPrefixed,
                    implode(',', array_fill(0, count($idDashboards), '?'))),
                array_merge(array($login), $idDashboards)
            );
        }
    }

    /** The stored spelling of a Matomo user's login, or $login itself when Matomo knows no such user. */
    public function storedLogin($login)
    {
        $stored = (string) ($this->canonicalLogin)((string) $login);

        return $stored !== '' ? $stored : (string) $login;
    }
}
