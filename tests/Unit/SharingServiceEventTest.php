<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Piwik;
use Piwik\Plugins\Dashboard\Model as DashboardModel;
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;
use Piwik\Plugins\FeinwerkDashboardSharing\SharingService;

/**
 * SharingService must announce override sync via events (the optional FeinwerkDashboardSegment plugin
 * observes them) instead of calling it directly. Sharing and DashboardModel are mocked, so no DB is needed
 * (subscribe() opens a DB transaction and is covered by the integration tests).
 *
 * @group FeinwerkDashboardSharing
 */
class SharingServiceEventTest extends TestCase
{
    /** @var array<string, array<int, array>> events received while recording, by event name */
    private static $received = array();
    private static $recording = false;
    private static $registered = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::$received = array();
        self::$recording = true;

        // EventDispatcher has no way to remove an observer, so register once and gate on $recording.
        if (!self::$registered) {
            foreach (array('copyWidgetOverrides', 'deleteWidgetOverrides') as $name) {
                $event = 'FeinwerkDashboardSharing.' . $name;
                Piwik::addAction($event, function () use ($event) {
                    if (self::$recording) {
                        self::$received[$event][] = func_get_args();
                    }
                });
            }
            self::$registered = true;
        }
    }

    protected function tearDown(): void
    {
        self::$recording = false;
        parent::tearDown();
    }

    public function test_syncLink_postsCopyWidgetOverrides_withOwnerAndSubscriberArgsInOrder()
    {
        $sharing = $this->createMock(Sharing::class);
        $sharing->method('getLink')->willReturn(array('idshare' => 7));
        $sharing->method('getShare')->willReturn(array('owner_login' => 'bob', 'iddashboard' => 3));

        $model = $this->createMock(DashboardModel::class);
        $model->method('getLayoutForUser')->willReturn(array(array('layout' => '{}')));

        $this->assertTrue((new SharingService($sharing, $model))->syncLink('alice', 12));

        $this->assertSame(
            array(array('bob', 3, 'alice', 12)),
            self::$received['FeinwerkDashboardSharing.copyWidgetOverrides'] ?? null
        );
        $this->assertArrayNotHasKey('FeinwerkDashboardSharing.deleteWidgetOverrides', self::$received);
    }

    public function test_syncLink_notLinked_postsNothing()
    {
        $sharing = $this->createMock(Sharing::class);
        $sharing->method('getLink')->willReturn(false);

        $this->assertFalse((new SharingService($sharing, $this->createMock(DashboardModel::class)))->syncLink('alice', 12));
        $this->assertSame(array(), self::$received);
    }

    public function test_unsubscribe_postsDeleteWidgetOverrides_withSubscriberAndDashboard()
    {
        $sharing = $this->createMock(Sharing::class);
        $sharing->method('getLink')->willReturn(array('idshare' => 7));

        (new SharingService($sharing, $this->createMock(DashboardModel::class)))->unsubscribe('alice', 12);

        $this->assertSame(
            array(array('alice', 12)),
            self::$received['FeinwerkDashboardSharing.deleteWidgetOverrides'] ?? null
        );
        $this->assertArrayNotHasKey('FeinwerkDashboardSharing.copyWidgetOverrides', self::$received);
    }

    public function test_unsubscribe_notLinked_postsNothing()
    {
        $sharing = $this->createMock(Sharing::class);
        $sharing->method('getLink')->willReturn(false);

        (new SharingService($sharing, $this->createMock(DashboardModel::class)))->unsubscribe('alice', 12);

        $this->assertSame(array(), self::$received);
    }
}
