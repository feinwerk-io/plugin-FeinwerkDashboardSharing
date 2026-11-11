<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\Promo;

use Piwik\Cache;
use Piwik\Common;
use Piwik\Piwik;
use Piwik\View;
use Piwik\Widget\WidgetConfig;
use Piwik\Widget\WidgetsList;

/**
 * The free placements of the Feinwerk promotion kit (docs/promo-kit.md, house rule docs/promotions.md): one banner on
 * Matomo's admin home (event Template.adminHome) and one context box at the end of matching Matomo report pages
 * (event Widget.filterWidgets adds a widget that the controller action promoBox renders). Paid plugins have neither.
 *
 * Each placement is shown once per page, whatever the number of Feinwerk plugins: the first plugin that fills it claims
 * it in Matomo's transient cache (key fwpromo.claimed.<placement>, value: the plugin name), the others leave it empty.
 * A box is only added to a page that already has widgets, so it never creates a page of its own.
 */
final class PromoPlacements
{
    /** the banner on Matomo's admin home (Administration > General) */
    public const BANNER = 'adminHome';
    /** placement => [category id, subcategory id] of the Matomo report page that gets the context box */
    public const PAGES = [
        // Acquisition > Campaigns, also with Marketing Campaigns Reporting (its reports use the same page)
        'campaigns' => ['Referrers_Referrers', 'Referrers_Campaigns'],
        // Ecommerce > Overview, exists only for websites with ecommerce
        'ecommerce' => ['Goals_Ecommerce', 'General_Overview'],
        // Goals > Overview
        'goals' => ['Goals_Goals', 'General_Overview'],
    ];
    /** request-level marker shared by all Feinwerk plugins */
    public const CLAIM_KEY = 'fwpromo.claimed.';
    /** controller action of the context box widget and its parameter (the message id) */
    public const BOX_ACTION = 'promoBox';
    public const BOX_PARAMETER = 'fwpromo';
    /** after Matomo's own widgets of the page */
    public const BOX_ORDER = 10000;

    /**
     * The message a placement shows: the first one with that placement whose condition holds. The ecommerce page
     * exists only for websites with ecommerce, so its condition holds there.
     *
     * @param callable(string): bool $isInstalled
     */
    public static function messageFor(string $placement, callable $isInstalled): ?string
    {
        foreach (PromoLine::candidates($isInstalled, true) as $id) {
            if ((PromoLine::MESSAGES[$id]['placement'] ?? null) === $placement) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Claims a placement for this request: true when it was free or is already this plugin's (the widget list can be
     * built more than once per request), false when another Feinwerk plugin fills it.
     *
     * @param \Matomo\Cache\Cache|null $cache Matomo's transient cache (null: the request's)
     */
    public static function claim(string $placement, string $plugin = PromoLine::PLUGIN, $cache = null): bool
    {
        $cache = $cache ?? Cache::getTransientCache();
        $key = self::CLAIM_KEY . $placement;
        if ($cache->contains($key)) {
            return $cache->fetch($key) === $plugin;
        }
        $cache->save($key, $plugin);

        return true;
    }

    /**
     * Template.adminHome: the banner, for admins and super users, once per page. Never breaks the page.
     *
     * @param string $out the HTML of the event, appended to
     */
    public static function adminHome(&$out): void
    {
        try {
            if (!PromoLine::shownToCurrentUser()) {
                return;
            }
            $id = self::messageFor(self::BANNER, [PromoLine::class, 'isInstalled']);
            if ($id === null || !self::claim(self::BANNER)) {
                return;
            }
            $out .= self::render(PromoLine::variables($id, PromoLine::language(), [Piwik::class, 'translate'], self::BANNER));
        } catch (\Throwable $e) {
            // a promotion never breaks Matomo's admin home
        }
    }

    /**
     * Widget.filterWidgets: one context box at the end of each matching report page that exists for this website.
     * Not for API calls with a token (a script, or a Widgetize embed): only the session's own interface asks for the
     * page list with force_api_session=1.
     */
    public static function addBoxes(WidgetsList $list): void
    {
        try {
            if (self::isApiCallWithoutSession(Piwik::getModule(), Common::getRequestVar('force_api_session', 0, 'int'))
                || !PromoLine::shownToCurrentUser()
            ) {
                return;
            }
            $pages = self::pagesWithWidgets($list->getWidgetConfigs());
            foreach (self::PAGES as $placement => $page) {
                if (!isset($pages[$page[0] . '/' . $page[1]])) {
                    continue;
                }
                $id = self::messageFor($placement, [PromoLine::class, 'isInstalled']);
                if ($id === null || !self::claim($placement)) {
                    continue;
                }
                $list->addWidgetConfig(self::boxConfig($placement, $id, Piwik::translate('FeinwerkDashboardSharing_PromoTip')));
            }
        } catch (\Throwable $e) {
            // a promotion never breaks a report page
        }
    }

    /** An API request that does not come from the session's own interface (force_api_session=1). */
    public static function isApiCallWithoutSession(string $module, int $forceApiSession): bool
    {
        return $module === 'API' && $forceApiSession !== 1;
    }

    /**
     * Pages that have at least one widget other than a promotion box.
     *
     * @param WidgetConfig[] $configs
     * @return array<string, true> keyed "<category id>/<subcategory id>"
     */
    public static function pagesWithWidgets(array $configs): array
    {
        $pages = [];
        foreach ($configs as $config) {
            if (array_key_exists(self::BOX_PARAMETER, $config->getParameters())) {
                continue;
            }
            $pages[$config->getCategoryId() . '/' . $config->getSubcategoryId()] = true;
        }

        return $pages;
    }

    /** The context box widget: wide, last on the page, not offered for dashboards or Widgetize. */
    public static function boxConfig(string $placement, string $id, string $name): WidgetConfig
    {
        $config = new WidgetConfig();
        $config->setCategoryId(self::PAGES[$placement][0]);
        $config->setSubcategoryId(self::PAGES[$placement][1]);
        $config->setModule(PromoLine::PLUGIN);
        $config->setAction(self::BOX_ACTION);
        $config->setParameters([self::BOX_PARAMETER => $id]);
        $config->setName($name);
        $config->setOrder(self::BOX_ORDER);
        $config->setIsWide();
        $config->setIsNotWidgetizable();

        return $config;
    }

    /**
     * The box of the controller action promoBox: '' for an unknown message, in an embed, for users who do not see
     * promotions and for an installed product. Never throws.
     */
    public static function box(string $id): string
    {
        try {
            $placement = PromoLine::MESSAGES[$id]['placement'] ?? '';
            if (!isset(self::PAGES[$placement])
                || PromoLine::isEmbedded(Piwik::getModule(), Common::getRequestVar('disableLink', 0, 'int'))
                || !PromoLine::shownToCurrentUser()
            ) {
                return '';
            }
            $product = PromoLine::MESSAGES[$id]['product'];
            if ($product !== null && PromoLine::isInstalled($product)) {
                return '';
            }

            return self::render(PromoLine::variables($id, PromoLine::language(), [Piwik::class, 'translate'], $placement));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @param array<string, mixed> $promo see PromoLine::variables() */
    public static function render(array $promo): string
    {
        $view = new View('@FeinwerkDashboardSharing/_promoBox');
        $view->promo = $promo;

        return $view->render();
    }
}
