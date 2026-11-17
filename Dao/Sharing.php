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
use Piwik\Plugins\FeinwerkDashboardSharing\Exception\LinkedForAnotherUserException;
use Piwik\Plugins\FeinwerkDashboardSharing\LoginMatch;
use Piwik\Plugins\UsersManager\Model as UsersModel;

/**
 * Registry of shared dashboards. A share makes one of the owner's dashboards importable by other
 * users — either by everyone (share_to_all) or by a list of target logins. Importing creates an
 * independent copy for the recipient (handled elsewhere); this Dao only tracks availability.
 *
 * The login columns have the binary collation of their character set (new installs, and the update for existing
 * tables), so the database compares logins exactly. Every row found by a login is still checked again with an exact
 * comparison (LoginMatch) before it is used or changed: until that update has run, a table may compare logins with a
 * collation that treats two different Matomo users as one.
 */
class Sharing
{
    /** table (without prefix) => its login column */
    private const LOGIN_COLUMNS = array(
        'shared_dashboard'        => 'owner_login',
        'shared_dashboard_target' => 'target_login',
        'shared_dashboard_link'   => 'subscriber_login',
    );

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

        // the tables' character set is the server's or Matomo's choice: give each login column its binary collation
        foreach (self::LOGIN_COLUMNS as $table => $column) {
            $type = $this->exactLoginColumnType($table, $column);
            if ($type !== null) {
                Db::exec(sprintf('ALTER TABLE `%s` MODIFY COLUMN `%s` %s', Common::prefixTable($table), $column, $type));
            }
        }
    }

    /** @return array<string,string> table (without prefix) => its login column */
    public static function loginColumns()
    {
        return self::LOGIN_COLUMNS;
    }

    /**
     * The column type that makes a login column compare exactly (the binary collation of the column's own character
     * set), or null when the column already compares exactly or does not exist. install() and the update for existing
     * tables both use it.
     *
     * @param string $table without prefix
     */
    public function exactLoginColumnType($table, $column)
    {
        $info = Db::fetchRow(
            'SELECT `CHARACTER_SET_NAME` AS cs, `COLLATION_NAME` AS coll FROM `information_schema`.`COLUMNS`
              WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = ?',
            array(Common::prefixTable($table), $column)
        );
        if (empty($info) || empty($info['cs']) || substr((string) $info['coll'], -4) === '_bin') {
            return null;
        }
        if (!preg_match('/^[a-z0-9]+$/', (string) $info['cs'])) {
            return null;
        }

        return sprintf('VARCHAR(100) CHARACTER SET %1$s COLLATE %1$s_bin NOT NULL', $info['cs']);
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
            throw new LinkedForAnotherUserException();
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
     * Targets stay as the owner typed them (writing the resolved login would show a user's real login to an owner who
     * may not list users), so the lookup also finds every case of the login and LoginMatch::targetIs() decides.
     */
    public function getSharesAvailableTo($login)
    {
        $login = (string) $login;
        $mine = array();
        $targets = Db::fetchAll(
            sprintf('SELECT `idshare`, `target_login` FROM `%s` WHERE ' . self::loginOrAnyCase('target_login'), $this->targetPrefixed),
            array($login, $login)
        );
        foreach ($targets as $row) {
            if (LoginMatch::targetIs((string) $row['target_login'], $login, $this->canonicalLogin)) {
                $mine[(int) $row['idshare']] = true;
            }
        }

        $where = 's.`share_to_all` = 1';
        if ($mine) {
            $where .= sprintf(' OR s.`idshare` IN (%s)', implode(',', array_keys($mine)));
        }
        $shares = Db::fetchAll(sprintf('SELECT s.* FROM `%s` s WHERE %s ORDER BY s.`idshare` DESC', $this->sharePrefixed, $where));

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
     * deletion named it (Matomo passes the caller's spelling, after the user row is gone), so rows are looked up in
     * every case of it. A row found that way goes when LoginMatch::goesWithDeletedUser() says it belongs to that user
     * or to no user; rows of another existing user stay.
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
            sprintf('SELECT `idshare`, `owner_login` FROM `%s` WHERE ' . self::loginOrAnyCase('owner_login'), $this->sharePrefixed),
            array($login, $login)
        );
        foreach ($owned as $row) {
            if ($goes((string) $row['owner_login'])) {
                $this->deleteShare((int) $row['idshare']);
            }
        }

        $targets = Db::fetchAll(
            sprintf('SELECT `idshare`, `target_login` FROM `%s` WHERE ' . self::loginOrAnyCase('target_login'), $this->targetPrefixed),
            array($login, $login)
        );
        $this->deleteByStoredLogin($this->targetPrefixed, 'target_login', 'idshare', array_filter($targets, static function ($row) use ($goes) {
            return $goes((string) $row['target_login']);
        }));

        $links = Db::fetchAll(
            sprintf('SELECT `subscriber_login`, `iddashboard` FROM `%s` WHERE ' . self::loginOrAnyCase('subscriber_login'), $this->linkPrefixed),
            array($login, $login)
        );
        $this->deleteByStoredLogin($this->linkPrefixed, 'subscriber_login', 'iddashboard', array_filter($links, static function ($row) use ($goes) {
            return $goes((string) $row['subscriber_login']);
        }));
    }

    /**
     * Delete the given rows, each by the login stored in it plus its id. The login and the id are the primary key, and
     * under any collation a key matches at most one row (a table without the binary collation cannot hold two logins it
     * compares as equal under one id), so exactly the given rows go.
     *
     * @param array<array<string,mixed>> $rows
     */
    private function deleteByStoredLogin($table, $loginColumn, $idColumn, array $rows)
    {
        $idsByLogin = array();
        foreach ($rows as $row) {
            $idsByLogin[(string) $row[$loginColumn]][] = (int) $row[$idColumn];
        }
        foreach ($idsByLogin as $stored => $ids) {
            Db::query(
                sprintf('DELETE FROM `%s` WHERE `%s` = ? AND `%s` IN (%s)', $table, $loginColumn, $idColumn,
                    implode(',', array_fill(0, count($ids), '?'))),
                array_merge(array((string) $stored), $ids)
            );
        }
    }

    /**
     * A condition that finds a login in every case (bound twice): the login columns compare exactly once the update has
     * given them the binary collation, while targets keep the case the owner typed and deletions name the login as the
     * caller spelled it. LoginMatch decides which of the rows found belong to the user.
     */
    private static function loginOrAnyCase($column)
    {
        return sprintf('(`%1$s` = ? OR LOWER(`%1$s`) = LOWER(?))', $column);
    }

    /** The stored spelling of a Matomo user's login, or $login itself when Matomo knows no such user. */
    public function storedLogin($login)
    {
        $stored = (string) ($this->canonicalLogin)((string) $login);

        return $stored !== '' ? $stored : (string) $login;
    }
}
