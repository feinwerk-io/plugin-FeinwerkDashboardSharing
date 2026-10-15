<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

use Piwik\Common;
use Piwik\Piwik;

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
        );
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
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = 'plugins/FeinwerkDashboardSharing/stylesheets/dashboardSharing.less';
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
    }

    public function deleteUser($userLogin)
    {
        (new Dao\Sharing())->deleteAllForUser($userLogin);
    }
}
