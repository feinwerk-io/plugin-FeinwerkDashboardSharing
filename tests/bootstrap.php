<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

// Local unit tests (vendor/bin/phpunit): no database. Matomo core classes come from a Matomo checkout.
// In CI the plugin lives in matomo/plugins/<Plugin> and Matomo's own runner is used; locally set MATOMO_PATH
// (the worktree is outside Matomo). Integration tests need Matomo's test framework and run in CI only.
$matomoPath = getenv('MATOMO_PATH') ?: dirname(__DIR__, 3);
if (!is_file($matomoPath . '/core/Version.php')) {
    fwrite(STDERR, "Matomo not found at {$matomoPath} - set MATOMO_PATH\n");
    exit(1);
}
if (!defined('PIWIK_INCLUDE_PATH')) {
    define('PIWIK_INCLUDE_PATH', $matomoPath);
}
date_default_timezone_set('UTC');

// Our autoloader first: Matomo's maps Piwik\Plugins\ to its own plugins/ directory (maybe another copy of this plugin).
require __DIR__ . '/../vendor/autoload.php';
require_once $matomoPath . '/vendor/autoload.php';

// Piwik::postEvent()/addAction() need Matomo's event dispatcher from the DI container, which Matomo's runner creates in
// CI. Locally there is none: provide one whose plugin manager has no plugins, so only the observers a test adds run.
$pluginManager = new class extends \Piwik\Plugin\Manager {
    public function __construct()
    {
    }

    public function getPluginsLoadedAndActivated()
    {
        return array();
    }
};
$container = new \Piwik\Container\Container(new \DI\Definition\Source\DefinitionArray());
$container->set(\Piwik\EventDispatcher::class, new \Piwik\EventDispatcher($pluginManager));
\Piwik\Container\StaticContainer::push($container);
