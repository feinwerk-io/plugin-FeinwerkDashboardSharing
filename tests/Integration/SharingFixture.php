<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Integration;

use Piwik\Plugins\UsersManager\Model as UsersModel;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;

/**
 * Websites and users for integration tests: a share is visible only to users who have a website in common with its
 * owner, so share owners in these tests are real users with access to a website.
 */
trait SharingFixture
{
    /** Matomo adds websites only for a super user: create them as one, whatever the test's own access is. */
    private function createWebsites(int $count): void
    {
        $wasSuperUser = FakeAccess::$superUser;
        FakeAccess::$superUser = true;
        try {
            for ($i = 0; $i < $count; $i++) {
                Fixture::createWebsite('2026-01-01 00:00:00');
            }
        } finally {
            FakeAccess::$superUser = $wasSuperUser;
        }
    }

    /** @param int[] $idSites websites the user gets view access to */
    private function createUser(string $login, array $idSites): void
    {
        $users = new UsersModel();
        $users->addUser($login, 'not-a-password-hash', $login . '@example.com', '2026-01-01 00:00:00');
        if (!empty($idSites)) {
            $users->addUserAccess($login, 'view', $idSites);
        }
    }
}
