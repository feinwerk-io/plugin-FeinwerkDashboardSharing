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

    /**
     * The new copy's key (subscriber, dashboard id) is held by another login that the table's collation treats as the
     * same ("ALICE" for alice): the subscription is refused and rolled back, the other row stays as it was.
     */
    public function test_subscribe_refusesAKeyOfAnotherLogin_andRollsBack()
    {
        $sharing = new Sharing();
        $bobDash = (int) (new DashboardModel())->createNewDashboardForUser('bob', 'Bob dashboard', '{}');
        $this->idShare = (int) $sharing->createShare('bob', $bobDash, 'Shared', true, array(), '2026-10-13 10:00:00');
        $other = (int) $sharing->createShare('bob', $bobDash, 'Other', true, array(), '2026-10-13 10:00:00');
        // alice has no dashboard yet: the subscription stores her default at id 1 and puts the copy at id 2
        $sharing->createLink('ALICE', 2, $other);
        if ($sharing->getLink('ALICE', 2) === false || (int) \Piwik\Db::fetchOne(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `subscriber_login` = ?', \Piwik\Common::prefixTable('shared_dashboard_link')),
            array('alice')
        ) === 0) {
            $this->markTestSkipped('the test database compares logins case-sensitively');
        }

        $refused = null;
        try {
            (new SharingService())->subscribe($this->idShare, 'alice');
        } catch (\Exception $e) {
            $refused = $e;
        }
        $this->assertNotNull($refused, 'the key (alice, 2) belongs to ALICE (the copy gets id 2 after the stored default)');
        $this->assertStringContainsString('another user', $refused->getMessage());

        $this->assertSame(array(), (new DashboardModel())->getAllDashboardsForUser('alice'), 'rolled back, no orphan dashboard');
        $this->assertSame($other, (int) $sharing->getLink('ALICE', 2)['idshare'], 'ALICE\'s subscription is unchanged');
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
