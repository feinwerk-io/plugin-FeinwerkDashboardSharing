<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\ShareAccess;
use Piwik\Plugins\UsersManager\Model as UsersModel;

/**
 * A share is visible only to users who can see at least one website the owner can see (super users see every share).
 * This keeps owner logins and share names away from users of other websites, who could not see those users in Matomo
 * either. The users model is mocked; the current user's own access is given directly.
 *
 * @group FeinwerkDashboardSharing
 */
class ShareAccessTest extends TestCase
{
    public function test_userWithACommonWebsite_seesTheShare()
    {
        $access = $this->access(array(1, 3), false, array('bob' => array(false, array(3))));

        $this->assertTrue($access->isVisibleToCurrentUser('bob'));
    }

    public function test_userWithoutACommonWebsite_doesNotSeeTheShare()
    {
        $access = $this->access(array(2), false, array('bob' => array(false, array(1, 3))));

        $this->assertFalse($access->isVisibleToCurrentUser('bob'));
    }

    public function test_superUser_seesEveryShare()
    {
        $access = $this->access(array(), true, array('bob' => array(false, array(7))));

        $this->assertTrue($access->isVisibleToCurrentUser('bob'));
    }

    public function test_shareOfASuperUser_isVisibleToEveryUserWithAWebsite()
    {
        $access = $this->access(array(5), false, array('root' => array(true, array())));

        $this->assertTrue($access->isVisibleToCurrentUser('root'));
    }

    public function test_userWithoutAnyWebsite_seesNoShare()
    {
        $access = $this->access(array(), false, array('root' => array(true, array()), 'bob' => array(false, array(1))));

        $this->assertFalse($access->isVisibleToCurrentUser('root'));
        $this->assertFalse($access->isVisibleToCurrentUser('bob'));
    }

    public function test_shareOfAnUnknownOwner_isNotVisible()
    {
        $access = $this->access(array(1), false, array());

        $this->assertFalse($access->isVisibleToCurrentUser('ghost'));
    }

    /**
     * @param int[] $mySites websites the current user can see
     * @param array<string, array{0:bool, 1:int[]}> $owners login => [super user?, websites]
     */
    private function access(array $mySites, bool $iAmSuperUser, array $owners): ShareAccess
    {
        $users = $this->createMock(UsersModel::class);
        $users->method('getUser')->willReturnCallback(function ($login) use ($owners) {
            return isset($owners[$login]) ? array('login' => $login, 'superuser_access' => $owners[$login][0] ? 1 : 0) : array();
        });
        $users->method('getSitesAccessFromUser')->willReturnCallback(function ($login) use ($owners) {
            $rows = array();
            foreach (isset($owners[$login]) ? $owners[$login][1] : array() as $idSite) {
                $rows[] = array('site' => (string) $idSite, 'access' => 'view');
            }
            return $rows;
        });

        return new class ($users, $mySites, $iAmSuperUser) extends ShareAccess {
            private $mySites;
            private $iAmSuperUser;

            public function __construct(UsersModel $users, array $mySites, bool $iAmSuperUser)
            {
                parent::__construct($users);
                $this->mySites = $mySites;
                $this->iAmSuperUser = $iAmSuperUser;
            }

            protected function currentUserIsSuperUser(): bool
            {
                return $this->iAmSuperUser;
            }

            protected function currentUserSiteIds(): array
            {
                return $this->mySites;
            }
        };
    }
}
