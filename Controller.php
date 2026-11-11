<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

use Piwik\Common;
use Piwik\DataTable\Renderer\Json;
use Piwik\Piwik;

class Controller extends \Piwik\Plugin\Controller
{
    public function shareDashboard()
    {
        $this->checkTokenInUrl();
        Piwik::checkUserIsNotAnonymous();

        $idDashboard  = Common::getRequestVar('idDashboard', 1, 'int');
        $name         = Common::unsanitizeInputValue(Common::getRequestVar('name', '', 'string'));
        $targetLogins = Common::getRequestVar('targetLogins', '', 'string');
        $shareToAll   = Common::getRequestVar('shareToAll', 0, 'int') === 1;

        $idShare = API::getInstance()->shareDashboard($idDashboard, $name, $targetLogins, $shareToAll);

        Json::sendHeaderJSON();
        return json_encode(array('result' => 'success', 'idShare' => $idShare));
    }

    public function unshareDashboard()
    {
        $this->checkTokenInUrl();
        Piwik::checkUserIsNotAnonymous();

        API::getInstance()->unshareDashboard(Common::getRequestVar('idShare', 0, 'int'));

        Json::sendHeaderJSON();
        return json_encode(array('result' => 'success'));
    }

    public function getMyShares()
    {
        Piwik::checkUserIsNotAnonymous();
        Json::sendHeaderJSON();
        return json_encode(API::getInstance()->getMyShares());
    }

    public function getAvailableShares()
    {
        Piwik::checkUserIsNotAnonymous();
        Json::sendHeaderJSON();
        return json_encode(API::getInstance()->getAvailableShares());
    }

    public function subscribeToShare()
    {
        $this->checkTokenInUrl();
        Piwik::checkUserIsNotAnonymous();

        $idDashboard = API::getInstance()->subscribeToShare(Common::getRequestVar('idShare', 0, 'int'));

        Json::sendHeaderJSON();
        return json_encode(array('result' => 'success', 'idDashboard' => $idDashboard));
    }

    public function unsubscribe()
    {
        $this->checkTokenInUrl();
        Piwik::checkUserIsNotAnonymous();

        API::getInstance()->unsubscribe(Common::getRequestVar('idDashboard', 0, 'int'));

        Json::sendHeaderJSON();
        return json_encode(array('result' => 'success'));
    }

    public function syncLinkedDashboard()
    {
        $this->checkTokenInUrl();
        Piwik::checkUserIsNotAnonymous();

        $result = API::getInstance()->syncLinkedDashboard(Common::getRequestVar('idDashboard', 0, 'int'));

        Json::sendHeaderJSON();
        return json_encode($result);
    }

    public function getLinkInfo()
    {
        Piwik::checkUserIsNotAnonymous();
        Json::sendHeaderJSON();
        return json_encode(API::getInstance()->getLinkInfo(Common::getRequestVar('idDashboard', 0, 'int')));
    }

    public function getShareableUsers()
    {
        Piwik::checkUserIsNotAnonymous();
        Common::sendHeader('Cache-Control: no-store, no-cache, must-revalidate');
        Json::sendHeaderJSON();
        return json_encode(API::getInstance()->getShareableUsers());
    }

    public function getMyLinkedDashboards()
    {
        Piwik::checkUserIsNotAnonymous();
        Common::sendHeader('Cache-Control: no-store, no-cache, must-revalidate');
        Json::sendHeaderJSON();
        return json_encode(API::getInstance()->getMyLinkedDashboards());
    }
}
