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
    /** Matomo's Dashboard plugin stores dashboard names with at most 100 characters (user_dashboard.name). */
    const DASHBOARD_NAME_MAX_LENGTH = 100;

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

        // Dashboard, overrides and link must appear together or not at all.
        $db = Db::get();
        $db->beginTransaction();
        try {
            $this->storeDefaultDashboardIfNone($subscriberLogin);

            // Use a name that does not collide with the subscriber's existing dashboards, so a
            // same-named dashboard is never confused with / appears to overwrite an existing one.
            $name = $this->uniqueName($subscriberLogin, $share['name']);

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

        if ((int) $idDashboard === 1) {
            // Up to 1.0.2 a subscription could take id 1, the user's main dashboard, which Matomo expects to exist:
            // give the user Matomo's default dashboard back there instead of deleting it.
            list($name, $layout) = $this->defaultDashboard();
            $this->dashboardModel->updateLayoutForUser($subscriberLogin, 1, $layout);
            $this->dashboardModel->updateDashboardName($subscriberLogin, 1, $name);
            return;
        }
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

    /**
     * A user who never changed their dashboard has only Matomo's built-in default (id 1, not stored), so a new dashboard
     * would get id 1 and take the place of the user's main dashboard. Store that default first, the way Matomo does on the
     * user's first change (default layout, name "Dashboard"), so a subscription gets an id of its own.
     */
    public function storeDefaultDashboardIfNone($login)
    {
        if (!empty($this->dashboardModel->getAllDashboardsForUser($login))) {
            return;
        }
        list($name, $layout) = $this->defaultDashboard();
        $this->dashboardModel->createNewDashboardForUser($login, $name, $layout);
    }

    /** @return array{0:string, 1:string} name and layout of Matomo's built-in default dashboard */
    protected function defaultDashboard()
    {
        return array(\Piwik\Piwik::translate('Dashboard_Dashboard'), (new \Piwik\Plugins\Dashboard\Dashboard())->getDefaultLayout());
    }

    /** The owner's layout, cleaned for the subscriber (see LayoutSanitizer), or false if the source is gone. */
    private function ownerLayout($ownerLogin, $ownerIdDashboard)
    {
        $rows = $this->dashboardModel->getLayoutForUser($ownerLogin, $ownerIdDashboard);
        if (empty($rows) || !isset($rows[0]['layout'])) {
            return false;
        }
        return (new LayoutSanitizer())->sanitize($rows[0]['layout']);
    }

    /** A dashboard name for $login that doesn't collide with their existing dashboards and fits Matomo's column. */
    protected function uniqueName($login, $desiredName)
    {
        $desiredName = mb_substr((string) $desiredName, 0, self::DASHBOARD_NAME_MAX_LENGTH);
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
        do {
            $suffix = ' (' . $i++ . ')';
            $candidate = mb_substr($desiredName, 0, self::DASHBOARD_NAME_MAX_LENGTH - mb_strlen($suffix)) . $suffix;
        } while (isset($existing[$candidate]));
        return $candidate;
    }
}
