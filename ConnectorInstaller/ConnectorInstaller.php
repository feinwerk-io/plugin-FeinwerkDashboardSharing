<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\ConnectorInstaller;

use Piwik\Cache;
use Piwik\Config;
use Piwik\Piwik;
use Piwik\Plugin\Manager as PluginManager;
use Piwik\SettingsPiwik;

/**
 * Feinwerk kit: a small installer for the Feinwerk Marketplace Connector in every Feinwerk plugin (Peter, 2026-11-19:
 * "jedes plugin soll einen kleinen installer für den feinwerk connector mitbringen. unter admin gibt es, wenn der
 * connector noch nicht instslliert ist, ein menüpunkt, der einem die installation und sktivieren mot einem klick
 * ermöglicht"; own off switch: "ja, mit eigenem Schalter umsetzen"). Design: /root/fw-docs/connector-installer-design.md.
 *
 * One Administration entry for super users while the connector is not active; one click installs it through Matomo's own
 * Marketplace installer (the path of the Install button of Matomo's Marketplace) and activates it, after Matomo's
 * password check. Only Matomo APIs and the plugin name: never the connector's classes (it is absent or switched off when
 * this shows). Not a promotion: `promotions = 0` does not hide it; `connector_installer = 0` in the plugin's section or
 * in [FeinwerkMarketplaceConnector] does.
 */
final class ConnectorInstaller
{
    public const CONNECTOR = 'FeinwerkMarketplaceConnector';
    public const PLUGIN = 'FeinwerkDashboardSharing';
    public const SWITCH = 'connector_installer';
    public const NONCE = 'FeinwerkDashboardSharing.connectorInstaller';
    /** the connector needs PHP 8.1 (its plugin.json) */
    public const PHP_FLOOR = '8.1.0';
    /** where the super user lands after the click: the connector's Discover tab (Connect, "Show Feinwerk plugins") */
    public const CONNECTOR_URL = 'index.php?module=FeinwerkMarketplaceConnector&action=index';
    /** where the page sends a super user when it has nothing to offer (switched off) */
    public const ADMIN_HOME_URL = 'index.php?module=CoreAdminHome&action=home';
    /** activation where plugin administration is switched off (an identifier admins type, not a translation) */
    public const CONSOLE_COMMAND = './console plugin:activate FeinwerkMarketplaceConnector';
    /** one entry across all Feinwerk plugins: the first that builds the menu claims it */
    public const CLAIM = 'fwconnector.installer.claimed';

    public const HIDDEN = 'hidden';
    public const INSTALL = 'install';
    public const ACTIVATE = 'activate';
    public const CONSOLE = 'console';
    public const MANUAL = 'manual';
    public const PHP = 'php';
    public const WORDPRESS = 'wordpress';

    /**
     * What the page offers. Pure.
     *
     * - hidden: no super user, switched off, or the connector is active;
     * - wordpress: Matomo for WordPress (the connector installs there as a WordPress plugin, never inside Matomo);
     * - php: PHP below the connector's floor;
     * - activate: its files are there (also a link or a git checkout), plugin administration allowed;
     * - console: its files are there, plugin administration switched off (activate on the command line);
     * - install: Matomo would offer its own one-click install (plugin administration, Marketplace, internet features,
     *   automatic updates possible: the same rule as the Install button of Matomo's Marketplace);
     * - manual: everything else, with instructions instead of a button.
     *
     * @param array{superUser: bool, enabled: bool, active: bool, present: bool, wordpress: bool, phpOk: bool,
     *              pluginsAdmin: bool, marketplace: bool, internet: bool, autoUpdate: bool} $env
     */
    public static function decide(array $env): string
    {
        if (!$env['superUser'] || !$env['enabled'] || $env['active']) {
            return self::HIDDEN;
        }
        if ($env['wordpress']) {
            return self::WORDPRESS;
        }
        if (!$env['phpOk']) {
            return self::PHP;
        }
        if ($env['present']) {
            return $env['pluginsAdmin'] ? self::ACTIVATE : self::CONSOLE;
        }

        return $env['pluginsAdmin'] && $env['marketplace'] && $env['internet'] && $env['autoUpdate']
            ? self::INSTALL
            : self::MANUAL;
    }

    /**
     * Where both actions send the super user instead of showing the page: the connector's own pages once it is active,
     * Matomo's admin home when the installer is switched off; null when the page has something to offer. Pure.
     *
     * @param array<string, bool> $env
     */
    public static function redirectFor(array $env): ?string
    {
        if (self::decide($env) !== self::HIDDEN) {
            return null;
        }

        return $env['active'] ? self::CONNECTOR_URL : self::ADMIN_HOME_URL;
    }

    /**
     * One config section's switch, read like `promotions`: 0, off, no, false and an empty value switch it off.
     *
     * @param array<string, mixed> $section
     */
    public static function switchOnBy(array $section): bool
    {
        if (!array_key_exists(self::SWITCH, $section)) {
            return true;
        }
        $value = $section[self::SWITCH];

        return $value !== false && $value !== null
            && !in_array(strtolower(trim((string) $value)), ['0', '', 'off', 'no', 'false'], true);
    }

    /** The current request's situation; never throws (on any doubt: hidden). @return array<string, bool> */
    public static function environment(): array
    {
        $env = array_fill_keys(
            ['superUser', 'enabled', 'active', 'present', 'wordpress', 'phpOk', 'pluginsAdmin', 'marketplace', 'internet', 'autoUpdate'],
            false
        );
        try {
            $env['superUser'] = Piwik::hasUserSuperUserAccess();
            if (!$env['superUser']) {
                return $env;
            }
            $config = Config::getInstance();
            $own = $config->{self::PLUGIN};
            $connector = $config->{self::CONNECTOR};
            $env['enabled'] = self::switchOnBy(is_array($own) ? $own : []) && self::switchOnBy(is_array($connector) ? $connector : []);
            $manager = PluginManager::getInstance();
            $env['active'] = $manager->isPluginActivated(self::CONNECTOR);
            if ($env['active'] || !$env['enabled']) {
                return $env;
            }
            // the files decide (a link or a git checkout counts too); one directory scan per request
            $env['present'] = $manager->isPluginInFilesystem(self::CONNECTOR);
            $env['wordpress'] = defined('ABSPATH') && function_exists('matomo_add_plugin');
            $env['phpOk'] = version_compare(PHP_VERSION, self::PHP_FLOOR, '>=');
            $env['pluginsAdmin'] = \Piwik\Plugins\CorePluginsAdmin\CorePluginsAdmin::isPluginsAdminEnabled();
            $env['marketplace'] = class_exists('Piwik\\Plugins\\Marketplace\\Marketplace')
                && \Piwik\Plugins\Marketplace\Marketplace::isMarketplaceEnabled();
            $env['internet'] = SettingsPiwik::isInternetEnabled();
            $env['autoUpdate'] = SettingsPiwik::isAutoUpdatePossible();
        } catch (\Throwable $e) {
            $env['superUser'] = false;
        }

        return $env;
    }

    /**
     * Claims the menu entry for this request: true when it was free or is already this plugin's, false when another
     * Feinwerk plugin shows it.
     *
     * @param \Matomo\Cache\Cache|null $cache Matomo's transient cache (null: the request's)
     */
    public static function claim(string $plugin = self::PLUGIN, $cache = null): bool
    {
        try {
            $cache = $cache ?? Cache::getTransientCache();
            $owner = $cache->fetch(self::CLAIM);
            if ($owner === false || $owner === null || $owner === '') {
                $cache->save(self::CLAIM, $plugin);

                return true;
            }

            return $owner === $plugin;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * The click: installs the connector when its files are missing, then activates it. Never throws; a failure comes back
     * with Matomo's message (as HTML only where Matomo's exception says so, as its own Marketplace does).
     *
     * @param callable(): void $install  Matomo's Marketplace installer for the connector
     * @param callable(): void $activate Matomo's plugin manager
     * @return array{ok: bool, message: string, html: bool}
     */
    public static function run(bool $present, callable $install, callable $activate): array
    {
        try {
            if (!$present) {
                $install();
            }
            $activate();

            return ['ok' => true, 'message' => '', 'html' => false];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'html' => method_exists($e, 'isHtmlMessage') && (bool) $e->isHtmlMessage(),
            ];
        }
    }

    /** The connector on plugins.matomo.org and as a ZIP from the Feinwerk store, for the manual way. @return array<string, string> */
    public static function manualLinks(): array
    {
        $campaign = 'mtm_campaign=connector-installer&mtm_source=' . self::PLUGIN;

        return [
            'marketplace' => 'https://plugins.matomo.org/' . self::CONNECTOR . '?' . $campaign,
            'download' => 'https://plugins.feinwerk.io/connector/download?' . $campaign,
        ];
    }

    /** Matomo Analytics, Marketplace in WordPress ('' outside WordPress). */
    public static function wordpressUrl(): string
    {
        return function_exists('admin_url') ? (string) admin_url('admin.php?page=matomo-marketplace&tab=install') : '';
    }
}
