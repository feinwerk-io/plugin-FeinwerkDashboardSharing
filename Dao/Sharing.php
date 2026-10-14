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

/**
 * Registry of shared dashboards. A share makes one of the owner's dashboards importable by other
 * users — either by everyone (share_to_all) or by a list of target logins. Importing creates an
 * independent copy for the recipient (handled elsewhere); this Dao only tracks availability.
 */
class Sharing
{
    private $shareTable = 'shared_dashboard';
    private $targetTable = 'shared_dashboard_target';
    private $linkTable = 'shared_dashboard_link';
    private $sharePrefixed;
    private $targetPrefixed;
    private $linkPrefixed;

    public function __construct()
    {
        $this->sharePrefixed = Common::prefixTable($this->shareTable);
        $this->targetPrefixed = Common::prefixTable($this->targetTable);
        $this->linkPrefixed = Common::prefixTable($this->linkTable);
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
        Db::query(
            sprintf('INSERT INTO `%s` (`subscriber_login`, `iddashboard`, `idshare`) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE `idshare` = VALUES(`idshare`)', $this->linkPrefixed),
            array($subscriberLogin, (int) $idDashboard, (int) $idShare)
        );
    }

    /** @return array|false the link row for a subscriber's dashboard, or false if not linked */
    public function getLink($subscriberLogin, $idDashboard)
    {
        return Db::fetchRow(
            sprintf('SELECT * FROM `%s` WHERE `subscriber_login` = ? AND `iddashboard` = ?', $this->linkPrefixed),
            array($subscriberLogin, (int) $idDashboard)
        );
    }

    public function isSubscribed($idShare, $subscriberLogin)
    {
        $row = Db::fetchOne(
            sprintf('SELECT 1 FROM `%s` WHERE `idshare` = ? AND `subscriber_login` = ? LIMIT 1', $this->linkPrefixed),
            array((int) $idShare, $subscriberLogin)
        );
        return !empty($row);
    }

    /** @return array|false the subscriber's link row for a given share, or false if not subscribed */
    public function getLinkForShare($idShare, $subscriberLogin)
    {
        return Db::fetchRow(
            sprintf('SELECT * FROM `%s` WHERE `idshare` = ? AND `subscriber_login` = ?', $this->linkPrefixed),
            array((int) $idShare, $subscriberLogin)
        );
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
                'SELECT l.`iddashboard`, s.`owner_login`
                 FROM `%s` l JOIN `%s` s ON l.`idshare` = s.`idshare`
                 WHERE l.`subscriber_login` = ?',
                $this->linkPrefixed,
                $this->sharePrefixed
            ),
            array($subscriberLogin)
        );
        $result = array();
        foreach ($rows as $row) {
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
        return Db::fetchAll(
            sprintf('SELECT * FROM `%s` WHERE `owner_login` = ? ORDER BY `idshare` DESC', $this->sharePrefixed),
            array($ownerLogin)
        );
    }

    /**
     * Shares the given user may import: shared to everyone, or explicitly to them; never their own.
     */
    public function getSharesAvailableTo($login)
    {
        return Db::fetchAll(
            sprintf(
                'SELECT s.* FROM `%s` s
                 WHERE s.`owner_login` != ?
                 AND (s.`share_to_all` = 1
                      OR EXISTS (SELECT 1 FROM `%s` t WHERE t.`idshare` = s.`idshare` AND t.`target_login` = ?))
                 ORDER BY s.`idshare` DESC',
                $this->sharePrefixed,
                $this->targetPrefixed
            ),
            array($login, $login)
        );
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

    /** Remove shares owned by, target rows referencing, and subscriptions of the given user. */
    public function deleteAllForUser($login)
    {
        $owned = Db::fetchAll(sprintf('SELECT `idshare` FROM `%s` WHERE `owner_login` = ?', $this->sharePrefixed), array($login));
        foreach ($owned as $row) {
            $this->deleteShare($row['idshare']);
        }
        Db::query(sprintf('DELETE FROM `%s` WHERE `target_login` = ?', $this->targetPrefixed), array($login));
        Db::query(sprintf('DELETE FROM `%s` WHERE `subscriber_login` = ?', $this->linkPrefixed), array($login));
    }
}
