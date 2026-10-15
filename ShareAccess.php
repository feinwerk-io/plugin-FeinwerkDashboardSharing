<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

use Piwik\Access;
use Piwik\Piwik;
use Piwik\Plugins\UsersManager\Model as UsersModel;

/**
 * Who may see a share: super users see every share; everyone else only shares of owners with whom they have at least
 * one website in common (any access level). This applies to shares with everyone and to shares with a list of logins,
 * so owner logins and share names never reach users of other websites, who could not see those users in Matomo either.
 */
class ShareAccess
{
    /** @var UsersModel */
    private $users;

    /** @var array<string, int[]|true> websites per owner login (true = super user), cached for the request */
    private $ownerSites = array();

    public function __construct(?UsersModel $users = null)
    {
        $this->users = $users ?: new UsersModel();
    }

    public function isVisibleToCurrentUser(string $ownerLogin): bool
    {
        if ($this->currentUserIsSuperUser()) {
            return true;
        }
        $mySites = array_map('intval', $this->currentUserSiteIds());
        if (empty($mySites)) {
            return false;
        }
        $ownerSites = $this->ownerSiteIds($ownerLogin);

        return $ownerSites === true || count(array_intersect($mySites, $ownerSites)) > 0;
    }

    /** @return int[]|true the websites $login has any access to, or true for a super user */
    private function ownerSiteIds(string $login)
    {
        if (!array_key_exists($login, $this->ownerSites)) {
            $user = $this->users->getUser($login);
            if (empty($user)) {
                $sites = array();
            } elseif (!empty($user['superuser_access'])) {
                $sites = true;
            } else {
                $sites = array_map(function ($row) {
                    return (int) $row['site'];
                }, $this->users->getSitesAccessFromUser($login));
            }
            $this->ownerSites[$login] = $sites;
        }

        return $this->ownerSites[$login];
    }

    protected function currentUserIsSuperUser(): bool
    {
        return Piwik::hasUserSuperUserAccess();
    }

    /** @return int[] */
    protected function currentUserSiteIds(): array
    {
        return Access::getInstance()->getSitesIdWithAtLeastViewAccess();
    }
}
