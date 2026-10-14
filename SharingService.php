<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

use Piwik\Db;
use Piwik\Plugins\Dashboard\Model as DashboardModel;
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;

/**
 * Subscribe/sync logic for shared dashboards. A subscription is a real dashboard owned by the
 * subscriber that mirrors a shared source: its layout AND per-widget overrides are overwritten
 * from the source on every sync, so the owner's changes propagate (read-only mirror).
 */
class SharingService
{
    /** @var Sharing */
    private $sharing;
    /** @var DashboardModel */
    private $dashboardModel;

    public function __construct(?Sharing $sharing = null, ?DashboardModel $dashboardModel = null)
    {
        $this->sharing = $sharing ?: new Sharing();
        $this->dashboardModel = $dashboardModel ?: new DashboardModel();
    }

    /**
     * Create a linked (auto-synced) dashboard for $subscriberLogin from a share.
     * @return int the new dashboard id
     */
    public function subscribe($idShare, $subscriberLogin)
    {
        $share = $this->sharing->getShare($idShare);
        if (!$share) {
            throw new \Exception('Share not found');
        }

        // Idempotent: a double-click, retry or direct request must not create a second copy of the
        // same share — return the dashboard the subscriber already has for it.
        $existing = $this->sharing->getLinkForShare($idShare, $subscriberLogin);
        if ($existing) {
            return (int) $existing['iddashboard'];
        }

        $layout = $this->ownerLayout($share['owner_login'], $share['iddashboard']);
        if ($layout === false) {
            throw new \Exception('Source dashboard not found');
        }

        // Use a name that does not collide with the subscriber's existing dashboards, so a
        // same-named dashboard is never confused with / appears to overwrite an existing one.
        $name = $this->uniqueName($subscriberLogin, $share['name']);

        // Dashboard, overrides and link must appear together or not at all.
        $db = Db::get();
        $db->beginTransaction();
        try {
            $newIdDashboard = $this->dashboardModel->createNewDashboardForUser($subscriberLogin, $name, $layout);
            // Per-widget overrides are owned by the optional FeinwerkDashboardSegment plugin; it observes this event.
            \Piwik\Piwik::postEvent('FeinwerkDashboardSharing.copyWidgetOverrides',
                array($share['owner_login'], $share['iddashboard'], $subscriberLogin, $newIdDashboard));
            $this->sharing->createLink($subscriberLogin, $newIdDashboard, $idShare);
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        return $newIdDashboard;
    }

    /**
     * Re-sync a subscriber's linked dashboard from its source (overwrites layout + overrides).
     * @return bool true if synced; false if the dashboard is not linked or the source is gone.
     */
    public function syncLink($subscriberLogin, $idDashboard)
    {
        $link = $this->sharing->getLink($subscriberLogin, $idDashboard);
        if (!$link) {
            return false;
        }
        $share = $this->sharing->getShare($link['idshare']);
        if (!$share) {
            return false; // share removed: keep the last synced copy as-is
        }
        $layout = $this->ownerLayout($share['owner_login'], $share['iddashboard']);
        if ($layout === false) {
            return false; // source dashboard deleted: keep the last synced copy
        }

        // Sync only the content (layout + overrides); keep the subscriber's (unique) dashboard
        // name stable so a re-sync never renames it onto a colliding name.
        $this->dashboardModel->updateLayoutForUser($subscriberLogin, $idDashboard, $layout);

        // The observer replaces the subscriber's overrides (delete, then copy from the source).
        \Piwik\Piwik::postEvent('FeinwerkDashboardSharing.copyWidgetOverrides',
            array($share['owner_login'], $share['iddashboard'], $subscriberLogin, $idDashboard));

        return true;
    }

    public function unsubscribe($subscriberLogin, $idDashboard)
    {
        if (!$this->sharing->getLink($subscriberLogin, $idDashboard)) {
            return;
        }
        $this->sharing->deleteLink($subscriberLogin, $idDashboard);
        \Piwik\Piwik::postEvent('FeinwerkDashboardSharing.deleteWidgetOverrides',
            array($subscriberLogin, $idDashboard));
        $this->dashboardModel->deleteDashboardForUser($idDashboard, $subscriberLogin);
    }

    /**
     * Clean up after one of $login's dashboards was removed outside this plugin (Matomo's "Remove dashboard"):
     * drop the subscription of a removed copy and the shares of a removed source. Matomo reuses the highest id for
     * the user's next dashboard, so a leftover row would attach to that new, unrelated dashboard.
     */
    public function forgetDashboard($login, $idDashboard)
    {
        if ($this->sharing->getLink($login, $idDashboard)) {
            $this->sharing->deleteLink($login, $idDashboard);
            \Piwik\Piwik::postEvent('FeinwerkDashboardSharing.deleteWidgetOverrides', array($login, $idDashboard));
        }
        foreach ($this->sharing->getSharesByOwner($login) as $share) {
            if ((int) $share['iddashboard'] === (int) $idDashboard) {
                $this->sharing->deleteShare((int) $share['idshare']);
            }
        }
    }

    private function ownerLayout($ownerLogin, $ownerIdDashboard)
    {
        $rows = $this->dashboardModel->getLayoutForUser($ownerLogin, $ownerIdDashboard);
        return (!empty($rows) && isset($rows[0]['layout'])) ? $rows[0]['layout'] : false;
    }

    /** A dashboard name for $login that doesn't collide with their existing dashboards. */
    private function uniqueName($login, $desiredName)
    {
        $existing = array();
        foreach ($this->dashboardModel->getAllDashboardsForUser($login) as $d) {
            if (isset($d['name'])) {
                $existing[$d['name']] = true;
            }
        }
        if (!isset($existing[$desiredName])) {
            return $desiredName;
        }
        $i = 2;
        while (isset($existing[$desiredName . ' (' . $i . ')'])) {
            $i++;
        }
        return $desiredName . ' (' . $i . ')';
    }
}
