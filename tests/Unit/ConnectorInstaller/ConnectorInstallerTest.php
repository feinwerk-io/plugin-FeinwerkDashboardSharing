<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit\ConnectorInstaller;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\ConnectorInstaller\ConnectorInstaller;

/**
 * The Feinwerk Connector installer (Peter, 2026-11-19): one entry for super users while the connector is not active,
 * one click through Matomo's own Marketplace installer, instructions where Matomo cannot install by itself.
 */
class ConnectorInstallerTest extends TestCase
{
    /** A super user on a Matomo that installs from its Marketplace, the connector absent. */
    private const READY = [
        'superUser' => true, 'enabled' => true, 'active' => false, 'present' => false, 'wordpress' => false,
        'phpOk' => true, 'pluginsAdmin' => true, 'marketplace' => true, 'internet' => true, 'autoUpdate' => true,
    ];

    public function test_one_click_install_where_matomo_offers_it(): void
    {
        self::assertSame(ConnectorInstaller::INSTALL, ConnectorInstaller::decide(self::READY));
    }

    public function test_nothing_for_other_users_when_switched_off_or_once_the_connector_is_active(): void
    {
        self::assertSame(ConnectorInstaller::HIDDEN, ConnectorInstaller::decide(['superUser' => false] + self::READY), 'admins and view users');
        self::assertSame(ConnectorInstaller::HIDDEN, ConnectorInstaller::decide(['enabled' => false] + self::READY), 'connector_installer = 0');
        self::assertSame(ConnectorInstaller::HIDDEN, ConnectorInstaller::decide(['active' => true, 'present' => true] + self::READY));
    }

    /** Matomo's own rule for its Install button: plugin administration, Marketplace, internet and automatic updates. */
    public function test_instructions_instead_of_a_button_where_matomo_cannot_install_by_itself(): void
    {
        foreach (['pluginsAdmin', 'marketplace', 'internet', 'autoUpdate'] as $switch) {
            self::assertSame(ConnectorInstaller::MANUAL, ConnectorInstaller::decide([$switch => false] + self::READY), $switch);
        }
    }

    public function test_activate_when_the_files_are_there(): void
    {
        $present = ['present' => true] + self::READY;

        self::assertSame(ConnectorInstaller::ACTIVATE, ConnectorInstaller::decide($present));
        self::assertSame(ConnectorInstaller::ACTIVATE, ConnectorInstaller::decide(['marketplace' => false, 'internet' => false] + $present), 'no download needed');
        self::assertSame(ConnectorInstaller::CONSOLE, ConnectorInstaller::decide(['pluginsAdmin' => false] + $present), 'plugin administration off');
    }

    /**
     * Regression (Campaign Score's review, 2026-11-19): the click action showed the page's "open the connector" fallback
     * while the installer was switched off; both actions now take this decision.
     */
    public function test_where_both_actions_send_the_super_user_instead_of_showing_the_page(): void
    {
        self::assertSame('index.php?module=CoreAdminHome&action=home', ConnectorInstaller::redirectFor(['enabled' => false] + self::READY), 'switched off');
        self::assertSame('index.php?module=FeinwerkMarketplaceConnector&action=index', ConnectorInstaller::redirectFor(['active' => true, 'present' => true] + self::READY));
        foreach ([self::READY, ['present' => true] + self::READY, ['marketplace' => false] + self::READY, ['present' => true, 'pluginsAdmin' => false] + self::READY] as $env) {
            self::assertNull(ConnectorInstaller::redirectFor($env), ConnectorInstaller::decide($env));
        }
    }

    public function test_php_and_wordpress_come_first(): void
    {
        self::assertSame(ConnectorInstaller::PHP, ConnectorInstaller::decide(['phpOk' => false] + self::READY));
        self::assertSame(ConnectorInstaller::PHP, ConnectorInstaller::decide(['phpOk' => false, 'present' => true] + self::READY), 'activation would fail too');
        self::assertSame(ConnectorInstaller::WORDPRESS, ConnectorInstaller::decide(['wordpress' => true] + self::READY));
        self::assertSame(ConnectorInstaller::WORDPRESS, ConnectorInstaller::decide(['wordpress' => true, 'present' => true] + self::READY), 'never inside Matomo there');
    }

    public function test_the_off_switch_reads_like_promotions(): void
    {
        self::assertTrue(ConnectorInstaller::switchOnBy([]));
        self::assertTrue(ConnectorInstaller::switchOnBy(['connector_installer' => '1']));
        self::assertTrue(ConnectorInstaller::switchOnBy(['promotions' => '0']), 'promotions = 0 does not hide the installer');
        foreach (['0', 0, '', 'off', 'No', 'false', false, null] as $off) {
            self::assertFalse(ConnectorInstaller::switchOnBy(['connector_installer' => $off]), var_export($off, true));
        }
    }

    public function test_one_entry_across_all_feinwerk_plugins(): void
    {
        $cache = new TransientCache();

        self::assertTrue(ConnectorInstaller::claim('FeinwerkDashboardSharing', $cache));
        self::assertTrue(ConnectorInstaller::claim('FeinwerkDashboardSharing', $cache), 'the menu can be built twice');
        self::assertFalse(ConnectorInstaller::claim('FeinwerkDeleteVisitsFilter', $cache));
    }

    public function test_the_click_installs_missing_files_then_activates(): void
    {
        $calls = [];
        $install = static function () use (&$calls): void {
            $calls[] = 'install';
        };
        $activate = static function () use (&$calls): void {
            $calls[] = 'activate';
        };

        self::assertSame(['ok' => true, 'message' => '', 'html' => false], ConnectorInstaller::run(false, $install, $activate));
        self::assertSame(['install', 'activate'], $calls);

        $calls = [];
        ConnectorInstaller::run(true, $install, $activate);
        self::assertSame(['activate'], $calls, 'files there: no download');
    }

    public function test_a_failure_comes_back_with_matomos_message_and_nothing_breaks(): void
    {
        $activated = false;
        $result = ConnectorInstaller::run(
            false,
            static function (): void {
                throw new HtmlInstallerException('The plugins folder is not <b>writable</b>.');
            },
            static function () use (&$activated): void {
                $activated = true;
            }
        );

        self::assertSame(['ok' => false, 'message' => 'The plugins folder is not <b>writable</b>.', 'html' => true], $result);
        self::assertFalse($activated, 'no activation after a failed install');
        self::assertFalse(ConnectorInstaller::run(true, static function (): void {
        }, static function (): void {
            throw new \RuntimeException('Activation failed');
        })['html'], 'plain messages stay text');
    }

    public function test_manual_links_carry_the_campaign_and_nothing_else(): void
    {
        self::assertSame([
            'marketplace' => 'https://plugins.matomo.org/FeinwerkMarketplaceConnector?mtm_campaign=connector-installer&mtm_source=FeinwerkDashboardSharing',
            'download' => 'https://plugins.feinwerk.io/connector/download?mtm_campaign=connector-installer&mtm_source=FeinwerkDashboardSharing',
        ], ConnectorInstaller::manualLinks());
        self::assertSame('', ConnectorInstaller::wordpressUrl(), 'outside WordPress');
    }
}

/** Matomo's PluginInstallerException with an HTML message (its isHtmlMessage() exists from Matomo 5.x on). */
final class HtmlInstallerException extends \Exception
{
    public function isHtmlMessage(): bool
    {
        return true;
    }
}

/** The part of Matomo's transient cache that claim() uses. */
final class TransientCache
{
    /** @var array<string, mixed> */
    private $values = [];

    /** @return mixed */
    public function fetch(string $id)
    {
        return $this->values[$id] ?? false;
    }

    /** @param mixed $value */
    public function save(string $id, $value): bool
    {
        $this->values[$id] = $value;

        return true;
    }
}
