<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

use Piwik\Common;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\Plugins\FeinwerkDashboardSharing\Feedback\PluginFeedback;
use Piwik\Plugins\FeinwerkDashboardSharing\Promo\PromoPlacements;

class FeinwerkDashboardSharing extends \Piwik\Plugin
{
    public function registerEvents()
    {
        return array(
            'AssetManager.getJavaScriptFiles'        => 'getJsFiles',
            'AssetManager.getStylesheetFiles'        => 'getStylesheetFiles',
            'Translate.getClientSideTranslationKeys' => 'getClientSideTranslationKeys',
            'Db.getTablesInstalled'                  => 'getTablesInstalled',
            'UsersManager.deleteUser'                => 'deleteUser',
            'API.Dashboard.removeDashboard.end'      => 'onDashboardRemoved',
            // Feinwerk promotion kit (docs/promo-kit.md of FeinwerkPageFilterReport): banner on the admin home,
            // context boxes at the end of matching report pages; the tip line sits in the share dialog (DialogNotes)
            'Template.adminHome'                     => 'addPromoBanner',
            'Widget.filterWidgets'                   => 'addPromoBoxes',
        );
    }

    /** Template.adminHome: the banner of the Feinwerk promotion kit, once per page across all Feinwerk plugins. */
    public function addPromoBanner(&$out)
    {
        PromoPlacements::adminHome($out);
    }

    /** Widget.filterWidgets: the context boxes of the Feinwerk promotion kit at the end of matching report pages. */
    public function addPromoBoxes($list)
    {
        PromoPlacements::addBoxes($list);
    }

    /**
     * Matomo's "Remove dashboard" goes through the API (Dashboard.removeDashboard); forget the shares and the
     * subscription of the removed dashboard. Fired only after the removal succeeded.
     */
    public function onDashboardRemoved(&$returnedValue, $extraInfo)
    {
        $params = isset($extraInfo['parameters']) && is_array($extraInfo['parameters']) ? $extraInfo['parameters'] : array();
        $idDashboard = isset($params['idDashboard']) ? (int) $params['idDashboard'] : 0;
        $login = !empty($params['login']) ? (string) $params['login'] : Piwik::getCurrentUserLogin();
        if ($idDashboard <= 0 || $login === '' || $login === 'anonymous') {
            return;
        }
        (new SharingService())->forgetDashboard($login, $idDashboard);
    }

    public function getJsFiles(&$jsFiles)
    {
        $jsFiles[] = 'plugins/FeinwerkDashboardSharing/javascripts/dashboardSharing.js';
        // the feedback question at the end of the share dialog (templates/_feedback.twig)
        $jsFiles[] = 'plugins/FeinwerkDashboardSharing/javascripts/feedback.js';
        // the Feinwerk promotion kit: tip line, admin home banner, context boxes (templates/_promo*.twig)
        $jsFiles[] = 'plugins/FeinwerkDashboardSharing/javascripts/promo.js';
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = 'plugins/FeinwerkDashboardSharing/stylesheets/dashboardSharing.less';
        $stylesheets[] = 'plugins/FeinwerkDashboardSharing/stylesheets/promo.css';
    }

    public function getClientSideTranslationKeys(&$keys)
    {
        $keys[] = 'FeinwerkDashboardSharing_ShareDashboard';
        $keys[] = 'FeinwerkDashboardSharing_SharedDashboards';
        $keys[] = 'FeinwerkDashboardSharing_ShareName';
        $keys[] = 'FeinwerkDashboardSharing_ShareWhoTitle';
        $keys[] = 'FeinwerkDashboardSharing_ShareToAll';
        $keys[] = 'FeinwerkDashboardSharing_ShareSpecificUsers';
        $keys[] = 'FeinwerkDashboardSharing_ShareEnterLogins';
        $keys[] = 'FeinwerkDashboardSharing_ShareUsersAdminOnly';
        $keys[] = 'FeinwerkDashboardSharing_ShareSubmit';
        $keys[] = 'FeinwerkDashboardSharing_Cancel';
        $keys[] = 'FeinwerkDashboardSharing_Close';
        $keys[] = 'FeinwerkDashboardSharing_MyShares';
        $keys[] = 'FeinwerkDashboardSharing_Unshare';
        $keys[] = 'FeinwerkDashboardSharing_Subscribe';
        $keys[] = 'FeinwerkDashboardSharing_Unsubscribe';
        $keys[] = 'FeinwerkDashboardSharing_Subscribers';
        $keys[] = 'FeinwerkDashboardSharing_NoShares';
        $keys[] = 'FeinwerkDashboardSharing_NoAvailableShares';
        $keys[] = 'FeinwerkDashboardSharing_SyncedBadge';
        $keys[] = 'FeinwerkDashboardSharing_SyncedNotice';
        $keys[] = 'FeinwerkDashboardSharing_ShareToAllLabel';
        $keys[] = 'FeinwerkDashboardSharing_ShareError';
    }

    public function getTablesInstalled(&$allTablesInstalled)
    {
        $allTablesInstalled[] = Common::prefixTable('shared_dashboard');
        $allTablesInstalled[] = Common::prefixTable('shared_dashboard_target');
        $allTablesInstalled[] = Common::prefixTable('shared_dashboard_link');
    }

    public function install()
    {
        (new Dao\Sharing())->install();
    }

    /** Users deleted while the plugin was inactive left their rows behind: remove them before they can match a new user. */
    public function activate()
    {
        try {
            (new Dao\Sharing())->deleteRowsOfDeletedUsers();
        } catch (\Exception $e) {
            // A failed cleanup must not keep the plugin from being activated.
            \Piwik\Log::warning('FeinwerkDashboardSharing: removing rows of deleted users failed: ' . $e->getMessage());
        }
    }

    public function uninstall()
    {
        (new Dao\Sharing())->uninstall();
        // the feedback throttles of all users and the server's vote counter ("_" is a LIKE wildcard)
        Option::deleteLike(str_replace('_', '\\_', PluginFeedback::THROTTLE_OPTION_PREFIX) . '%');
    }

    public function deleteUser($userLogin)
    {
        (new Dao\Sharing())->deleteAllForUser($userLogin);
        // the user's feedback throttles (time of the last vote per question; "_" is a LIKE wildcard)
        Option::deleteLike(str_replace('_', '\\_', PluginFeedback::userOptionPrefix((string) $userLogin)) . '%');
    }
}
