<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Integration;

use Piwik\Plugins\Dashboard\Model as DashboardModel;
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;
use Piwik\Plugins\FeinwerkDashboardSharing\SharingService;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * subscribe() creates the subscriber's dashboard, announces the per-widget override copy and creates the
 * link as one unit: the copyWidgetOverrides event fires inside the transaction (after the dashboard exists,
 * before the link), and a failing observer rolls the whole subscription back — no orphan dashboard, no link.
 *
 * @group FeinwerkDashboardSharing
 */
class SubscribeAtomicityTest extends IntegrationTestCase
{
    /** @var string '' = observer idle, 'record' = capture state, 'throw' = simulate a failing observer */
    private $observerMode = '';
    /** @var int|null share the observer inspects */
    private $idShare;
    /** @var array state captured by the observer while the event fires */
    private $seen = array();

    public function setUp(): void
    {
        parent::setUp();
        (new Sharing())->install();
    }

    public function test_subscribe_postsCopyEventInsideTransaction_afterDashboardBeforeLink()
    {
        $bobDash = (int) (new DashboardModel())->createNewDashboardForUser('bob', 'Bob dashboard', '{}');
        $this->idShare = (int) (new Sharing())->createShare('bob', $bobDash, 'Shared', true, array(), '2026-10-13 10:00:00');

        $this->observerMode = 'record';
        $newId = (int) (new SharingService())->subscribe($this->idShare, 'alice');

        $this->assertSame(array('bob', $bobDash, 'alice', $newId), $this->seen['args']);
        $this->assertTrue($this->seen['dashboardExisted'], 'the subscriber dashboard must exist when the event fires');
        $this->assertFalse($this->seen['linkExisted'], 'the link must be created after the event');
        $this->assertNotFalse((new Sharing())->getLinkForShare($this->idShare, 'alice'));
    }

    public function test_subscribe_observerThrows_rollsBackDashboardAndLink()
    {
        $bobDash = (int) (new DashboardModel())->createNewDashboardForUser('bob', 'Bob dashboard', '{}');
        $this->idShare = (int) (new Sharing())->createShare('bob', $bobDash, 'Shared', true, array(), '2026-10-13 10:00:00');

        $this->observerMode = 'throw';
        try {
            (new SharingService())->subscribe($this->idShare, 'alice');
            $this->fail('subscribe() must rethrow the observer failure');
        } catch (\Exception $e) {
            $this->assertSame('observer failed', $e->getMessage());
        }

        $this->assertSame(array(), (new DashboardModel())->getAllDashboardsForUser('alice'), 'no orphan dashboard');
        $this->assertFalse((new Sharing())->getLinkForShare($this->idShare, 'alice'), 'no link');
        $this->assertSame(0, (int) (new Sharing())->getSubscriberCount($this->idShare));
    }

    public function provideContainerConfig()
    {
        return array(
            'observers.global' => \Piwik\DI::add(array(
                array('FeinwerkDashboardSharing.copyWidgetOverrides', \Piwik\DI::value(
                    function ($ownerLogin, $ownerIdDashboard, $subscriberLogin, $newIdDashboard) {
                        if ($this->observerMode === 'throw') {
                            throw new \Exception('observer failed');
                        }
                        if ($this->observerMode === 'record') {
                            $this->seen = array(
                                'args' => array($ownerLogin, (int) $ownerIdDashboard, $subscriberLogin, (int) $newIdDashboard),
                                'dashboardExisted' => !empty((new DashboardModel())->getLayoutForUser($subscriberLogin, $newIdDashboard)),
                                'linkExisted' => (bool) (new Sharing())->getLinkForShare($this->idShare, $subscriberLogin),
                            );
                        }
                    }
                )),
            )),
        );
    }
}
