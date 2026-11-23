<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\DataTable\Renderer\Json;
use Piwik\Nonce;
use Piwik\Piwik;
use Piwik\Plugin\Manager as PluginManager;
use Piwik\Plugins\FeinwerkDashboardSharing\ConnectorInstaller\ConnectorInstaller;
use Piwik\Url;
use Piwik\View;

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

    /**
     * Kit: the Feinwerk Connector installer page (ConnectorInstaller/ConnectorInstaller.php). Super users only.
     */
    public function connectorInstaller()
    {
        Piwik::checkUserHasSuperUserAccess();
        $env = ConnectorInstaller::environment();
        $redirect = ConnectorInstaller::redirectFor($env);
        if ($redirect !== null) {
            Url::redirectToUrl($redirect);

            return '';
        }

        return $this->renderConnectorInstaller($env, null);
    }

    /**
     * Kit: the click. Matomo's password check first (it comes back here by GET with these parameters), then the nonce
     * (verified and discarded, as Matomo's own install action does), then install when the files are missing, and
     * activate. Success: on to the connector; any failure: back on the page with Matomo's reason.
     */
    public function connectorInstallerRun()
    {
        Piwik::checkUserHasSuperUserAccess();
        $env = ConnectorInstaller::environment();
        $redirect = ConnectorInstaller::redirectFor($env);
        if ($redirect !== null) {
            // active: the connector; switched off: admin home (never the page's "open the connector" fallback)
            Url::redirectToUrl($redirect);

            return '';
        }
        $state = ConnectorInstaller::decide($env);
        if ($state !== ConnectorInstaller::INSTALL && $state !== ConnectorInstaller::ACTIVATE) {
            return $this->renderConnectorInstaller($env, null);
        }

        $nonce = Common::getRequestVar('nonce', '', 'string');
        $verified = StaticContainer::get(\Piwik\Plugins\Login\PasswordVerifier::class)->requirePasswordVerifiedRecently([
            'module' => ConnectorInstaller::PLUGIN,
            'action' => 'connectorInstallerRun',
            'nonce' => $nonce,
        ]);
        if (!$verified) {
            // Matomo is sending the user to its password page (it returns only when its redirect is off)
            return '';
        }

        $error = Nonce::verifyNonceWithErrorMessage(ConnectorInstaller::NONCE, $nonce);
        if ($error !== '') {
            // Matomo's own reason, from its translations (may hold its FAQ link)
            return $this->renderConnectorInstaller($env, ['ok' => false, 'message' => $error, 'html' => true]);
        }
        Nonce::discardNonce(ConnectorInstaller::NONCE);

        $result = ConnectorInstaller::run(
            $env['present'],
            static function (): void {
                (new \Piwik\Plugins\CorePluginsAdmin\PluginInstaller())->installOrUpdatePluginFromMarketplace(ConnectorInstaller::CONNECTOR);
            },
            static function (): void {
                PluginManager::getInstance()->activatePlugin(ConnectorInstaller::CONNECTOR);
            }
        );
        if ($result['ok']) {
            Url::redirectToUrl(ConnectorInstaller::CONNECTOR_URL);
        }

        return $this->renderConnectorInstaller(ConnectorInstaller::environment(), $result);
    }

    /**
     * @param array<string, bool> $env
     * @param array{ok: bool, message: string, html: bool}|null $result
     */
    private function renderConnectorInstaller(array $env, ?array $result): string
    {
        $view = new View('@FeinwerkDashboardSharing/connectorInstaller');
        // an admin page without a website: setGeneralVariablesView() needs idSite (the menu entry is reached from
        // admin pages without one); the admin variables also send X-Frame-Options as enable_framed_settings says
        $this->setBasicVariablesView($view);
        \Piwik\Plugin\ControllerAdmin::setBasicVariablesAdminView($view);
        $state = ConnectorInstaller::decide($env);
        $view->installerState = $state;
        $view->installerError = $result !== null && !$result['ok'] ? $result : null;
        $view->installerNonce = $state === ConnectorInstaller::INSTALL || $state === ConnectorInstaller::ACTIVATE
            ? Nonce::getNonce(ConnectorInstaller::NONCE)
            : '';
        $view->installerLinks = ConnectorInstaller::manualLinks();
        $view->installerWordPressUrl = ConnectorInstaller::wordpressUrl();
        $view->installerConnectorUrl = ConnectorInstaller::CONNECTOR_URL;
        $view->installerPhpFloor = ConnectorInstaller::PHP_FLOOR;
        $view->installerPluginsAdmin = $env['pluginsAdmin'];
        $view->installerConsoleCommand = ConnectorInstaller::CONSOLE_COMMAND;

        return $view->render();
    }
}
