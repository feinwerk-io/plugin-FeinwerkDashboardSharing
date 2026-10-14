<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

use Piwik\Piwik;
use Piwik\Plugins\Dashboard\Model as DashboardModel;
use Piwik\Plugins\UsersManager\API as UsersManagerAPI;
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;

/**
 * FeinwerkDashboardSharing API: sharing of dashboards as auto-synced subscriptions.
 *
 * @method static API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    /** @var Sharing */
    private $sharing;
    /** @var SharingService */
    private $sharingService;

    public function __construct(?Sharing $sharing = null, ?SharingService $sharingService = null)
    {
        $this->sharing = $sharing ?: new Sharing();
        $this->sharingService = $sharingService ?: new SharingService();
    }

    /**
     * Share one of the current user's dashboards so others can subscribe to it.
     * @param string|array $targetLogins comma-separated logins (or array); ignored if $shareToAll
     * @return int the new share id
     */
    public function shareDashboard(int $idDashboard, string $name, $targetLogins = '', bool $shareToAll = false): int
    {
        Piwik::checkUserIsNotAnonymous();
        $login = Piwik::getCurrentUserLogin();
        $this->checkDashboardOwnedBy($login, $idDashboard);

        $targets = is_array($targetLogins)
            ? $targetLogins
            : array_filter(array_map('trim', explode(',', (string) $targetLogins)));
        if (!$shareToAll && empty($targets)) {
            throw new \Exception('A share needs at least one target user, or must be shared to all.');
        }
        if (trim($name) === '') {
            $name = 'Shared dashboard';
        }

        return $this->sharing->createShare($login, $idDashboard, $name, $shareToAll, $targets, date('Y-m-d H:i:s'));
    }

    public function unshareDashboard(int $idShare): void
    {
        Piwik::checkUserIsNotAnonymous();
        $share = $this->sharing->getShare($idShare);
        if ($share && $share['owner_login'] === Piwik::getCurrentUserLogin()) {
            $this->sharing->deleteShare($idShare);
        }
    }

    public function getMyShares(): array
    {
        Piwik::checkUserIsNotAnonymous();
        $login = Piwik::getCurrentUserLogin();
        $result = array();
        foreach ($this->sharing->getSharesByOwner($login) as $share) {
            // A share whose source dashboard was deleted is dead. Drop it so it is neither listed
            // nor — should the deleted dashboard's id be reused for a new dashboard — able to serve
            // that unrelated dashboard's content to the original recipients.
            if (!$this->dashboardExists($login, (int) $share['iddashboard'])) {
                $this->sharing->deleteShare((int) $share['idshare']);
                continue;
            }
            $result[] = array(
                'idshare'      => (int) $share['idshare'],
                'iddashboard'  => (int) $share['iddashboard'],
                'name'         => $share['name'],
                'share_to_all' => (int) $share['share_to_all'],
                'targets'      => $this->sharing->getTargets($share['idshare']),
                'subscribers'  => $this->sharing->getSubscriberCount($share['idshare']),
            );
        }
        return $result;
    }

    public function getAvailableShares(): array
    {
        Piwik::checkUserIsNotAnonymous();
        $login = Piwik::getCurrentUserLogin();
        $result = array();
        foreach ($this->sharing->getSharesAvailableTo($login) as $share) {
            if ($this->sharing->isSubscribed($share['idshare'], $login)) {
                continue;
            }
            // Skip shares whose source dashboard no longer exists (subscribing would fail anyway).
            // Not deleted here: the caller is not the owner.
            if (!$this->dashboardExists($share['owner_login'], (int) $share['iddashboard'])) {
                continue;
            }
            $result[] = array(
                'idshare' => (int) $share['idshare'],
                'owner'   => $share['owner_login'],
                'name'    => $share['name'],
            );
        }
        return $result;
    }

    /** Subscribe to a share: creates a linked, auto-synced dashboard. Returns the new idDashboard. */
    public function subscribeToShare(int $idShare): int
    {
        Piwik::checkUserIsNotAnonymous();
        $login = Piwik::getCurrentUserLogin();

        $available = false;
        foreach ($this->sharing->getSharesAvailableTo($login) as $share) {
            if ((int) $share['idshare'] === $idShare) {
                $available = true;
                break;
            }
        }
        if (!$available) {
            throw new \Exception('Share not available');
        }
        return $this->sharingService->subscribe($idShare, $login);
    }

    public function unsubscribe(int $idDashboard): void
    {
        Piwik::checkUserIsNotAnonymous();
        $this->sharingService->unsubscribe(Piwik::getCurrentUserLogin(), $idDashboard);
    }

    /** @return array{synced:bool} */
    public function syncLinkedDashboard(int $idDashboard): array
    {
        Piwik::checkUserIsNotAnonymous();
        return array('synced' => $this->sharingService->syncLink(Piwik::getCurrentUserLogin(), $idDashboard));
    }

    /** @return array{linked:bool, owner?:string} */
    public function getLinkInfo(int $idDashboard): array
    {
        Piwik::checkUserIsNotAnonymous();
        $link = $this->sharing->getLink(Piwik::getCurrentUserLogin(), $idDashboard);
        if (!$link) {
            return array('linked' => false);
        }
        $share = $this->sharing->getShare($link['idshare']);
        return array('linked' => true, 'owner' => $share ? $share['owner_login'] : '');
    }

    /**
     * The current user's subscribed (linked) dashboards, with the source owner — used to mark
     * those dashboards in the dashboard tab menu.
     * @return array<array{iddashboard:int, owner:string}>
     */
    public function getMyLinkedDashboards(): array
    {
        Piwik::checkUserIsNotAnonymous();
        return $this->sharing->getSubscribedDashboards(Piwik::getCurrentUserLogin());
    }

    /** Users the current user can target when sharing (only those they may list; others get []). */
    public function getShareableUsers(): array
    {
        Piwik::checkUserIsNotAnonymous();
        $login = Piwik::getCurrentUserLogin();

        // Call the API class directly (a Request::processRequest sub-call mis-resolves the output
        // renderer in some contexts and returns nothing). getUsers() enforces the admin permission
        // itself, so a non-admin simply gets an empty list rather than an error.
        try {
            $users = UsersManagerAPI::getInstance()->getUsers();
        } catch (\Exception $e) {
            return array();
        }

        $result = array();
        foreach ($users as $user) {
            $u = $user['login'];
            if ($u !== $login && $u !== 'anonymous') {
                $result[] = $u;
            }
        }
        return $result;
    }

    private function checkDashboardOwnedBy(string $login, int $idDashboard): void
    {
        if (!$this->dashboardExists($login, $idDashboard)) {
            throw new \Exception('Dashboard not found');
        }
    }

    /** True when $login still has a dashboard with this id (used to drop/skip dead shares). */
    private function dashboardExists(string $login, int $idDashboard): bool
    {
        $rows = (new DashboardModel())->getLayoutForUser($login, $idDashboard);
        return !empty($rows);
    }
}
