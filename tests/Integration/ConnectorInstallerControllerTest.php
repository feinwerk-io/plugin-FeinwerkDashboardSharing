<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Integration;

use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Plugins\FeinwerkDashboardSharing\ConnectorInstaller\ConnectorInstaller;
use Piwik\Plugins\FeinwerkDashboardSharing\Controller;
use Piwik\Plugins\Login\PasswordVerifier;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * Regressions from Campaign Score's review of the connector installer (2026-11-19; test shape from Conversions API):
 * the page opens without a website in the request (it ran setGeneralVariablesView(), which needs idSite), follows
 * Matomo's frame rule for admin pages, the click action sends a super user to the admin home when there is nothing to
 * offer, and it stops when Matomo's password check sends the user to its page.
 *
 * @group FeinwerkDashboardSharing
 */
class ConnectorInstallerControllerTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private $get = [];

    /** @var array<string, mixed> */
    private $general = [];

    public function provideContainerConfig()
    {
        return ['Piwik\\Access' => new FakeAccess()];
    }

    public function setUp(): void
    {
        parent::setUp();
        FakeAccess::$superUser = true;
        FakeAccess::$superUserLogin = 'superUserLogin';
        // a Matomo always has a website (its top menu needs one); the request names none
        Fixture::createWebsite('2026-01-01 00:00:00', 0, 'Shop', 'https://shop.example', 1, null, null, 'UTC');
        $this->get = $_GET;
        $this->general = Config::getInstance()->General;
        unset($_GET['idSite'], $_POST['idSite']);
        Config::getInstance()->FeinwerkDashboardSharing = [];
        Config::getInstance()->FeinwerkMarketplaceConnector = [];
    }

    public function tearDown(): void
    {
        $_GET = $this->get;
        Config::getInstance()->General = $this->general;
        Config::getInstance()->FeinwerkDashboardSharing = [];
        Config::getInstance()->FeinwerkMarketplaceConnector = [];
        parent::tearDown();
    }

    public function test_the_page_opens_without_a_website(): void
    {
        $this->assertOffered();

        $html = (new Controller())->connectorInstaller();

        self::assertStringContainsString('class="card fwci"', $html);
    }

    public function test_the_page_follows_matomos_frame_rule_for_admin_pages(): void
    {
        $this->assertOffered();
        $general = Config::getInstance()->General;
        // report pages may be framed, settings pages not: the installer is a settings page
        $general['enable_framed_pages'] = 1;
        $general['enable_framed_settings'] = 0;
        Config::getInstance()->General = $general;

        $html = (new Controller())->connectorInstaller();

        self::assertStringContainsString('top.location = self.location', $html, "the frame guard of Matomo's admin pages");
    }

    public function test_the_click_leads_to_the_admin_home_when_there_is_nothing_to_offer(): void
    {
        Config::getInstance()->FeinwerkDashboardSharing = [ConnectorInstaller::SWITCH => '0'];
        self::assertSame(ConnectorInstaller::HIDDEN, ConnectorInstaller::decide(ConnectorInstaller::environment()));

        // in CLI mode Matomo throws instead of sending the redirect
        $this->expectExceptionMessage('index.php?module=CoreAdminHome&action=home');

        (new Controller())->connectorInstallerRun();
    }

    public function test_the_click_stops_when_the_password_check_sends_the_user_to_its_page(): void
    {
        $state = ConnectorInstaller::decide(ConnectorInstaller::environment());
        if ($state !== ConnectorInstaller::INSTALL && $state !== ConnectorInstaller::ACTIVATE) {
            self::markTestSkipped('this Matomo offers no one-click install (state ' . $state . ')');
        }
        $verifier = $this->createMock(PasswordVerifier::class);
        // Matomo has sent its password page; true would mean "verified recently"
        $verifier->method('requirePasswordVerifiedRecently')->willReturn(null);
        StaticContainer::getContainer()->set(PasswordVerifier::class, $verifier);

        self::assertSame('', (new Controller())->connectorInstallerRun());
    }

    private function assertOffered(): void
    {
        self::assertNotSame(
            ConnectorInstaller::HIDDEN,
            ConnectorInstaller::decide(ConnectorInstaller::environment()),
            'precondition: a super user, the installer switched on, the connector not active'
        );
    }
}
