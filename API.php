<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

use Piwik\Container\StaticContainer;
use Piwik\Piwik;
use Piwik\Plugin\Manager as PluginManager;
use Piwik\Plugins\Dashboard\Model as DashboardModel;
use Piwik\Plugins\UsersManager\API as UsersManagerAPI;
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;
use Piwik\Plugins\FeinwerkDashboardSharing\Feedback\FeedbackVotes;
use Piwik\Plugins\FeinwerkDashboardSharing\Feedback\PluginFeedback;
use Piwik\Translation\Translator;
use Piwik\Version;

/**
 * FeinwerkDashboardSharing API: sharing of dashboards as auto-synced subscriptions.
 *
 * @method static API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    /** @var Sharing */
    private $sharing;
    /** @var SharingService */
    private $sharingService;
    /** @var DashboardModel */
    private $dashboardModel;
    /** @var ShareAccess|null created on first use */
    private $shareAccess;

    public function __construct(
        ?Sharing $sharing = null,
        ?SharingService $sharingService = null,
        ?DashboardModel $dashboardModel = null,
        ?ShareAccess $shareAccess = null
    ) {
        $this->sharing = $sharing ?: new Sharing();
        $this->sharingService = $sharingService ?: new SharingService();
        $this->dashboardModel = $dashboardModel ?: new DashboardModel();
        $this->shareAccess = $shareAccess;
    }

    /**
     * Share one of the current user's dashboards so others can subscribe to it. The name is stored as entered (also
     * when called through the HTTP API) and always output as text.
     * @unsanitized
     * @param string|array $targetLogins comma-separated logins (or array); ignored if $shareToAll
     * @return int the new share id
     */
    public function shareDashboard(int $idDashboard, string $name, $targetLogins = '', bool $shareToAll = false): int
    {
        $login = $this->checkAndGetLogin();
        $this->checkDashboardOwnedBy($login, $idDashboard);

        // Matomo 6 no longer trims request values: trim here, whether they come from the share dialog or the HTTP API.
        $targets = is_array($targetLogins) ? $targetLogins : explode(',', (string) $targetLogins);
        $targets = array_filter(array_map(function ($target) {
            return is_scalar($target) ? trim((string) $target) : '';
        }, $targets), 'strlen');
        if (!$shareToAll && empty($targets)) {
            throw new \Exception('A share needs at least one target user, or must be shared to all.');
        }
        // The name becomes the subscribers' dashboard name, which Matomo stores with at most 100 characters.
        $name = trim(mb_substr(trim($name), 0, SharingService::DASHBOARD_NAME_MAX_LENGTH));
        if ($name === '') {
            $name = 'Shared dashboard';
        }

        return $this->sharing->createShare($login, $idDashboard, $name, $shareToAll, $targets, date('Y-m-d H:i:s'));
    }

    public function unshareDashboard(int $idShare): void
    {
        Piwik::checkUserIsNotAnonymous();
        $share = $this->sharing->getShare($idShare);
        if ($share && $share['owner_login'] === Piwik::getCurrentUserLogin()) {
            $this->sharing->deleteShare($idShare);
        }
    }

    public function getMyShares(): array
    {
        Piwik::checkUserIsNotAnonymous();
        $login = Piwik::getCurrentUserLogin();
        $result = array();
        foreach ($this->sharing->getSharesByOwner($login) as $share) {
            // A share whose source dashboard was deleted is dead. Drop it so it is neither listed
            // nor — should the deleted dashboard's id be reused for a new dashboard — able to serve
            // that unrelated dashboard's content to the original recipients.
            if (!$this->dashboardExists($login, (int) $share['iddashboard'])) {
                $this->sharing->deleteShare((int) $share['idshare']);
                continue;
            }
            $result[] = array(
                'idshare'      => (int) $share['idshare'],
                'iddashboard'  => (int) $share['iddashboard'],
                'name'         => $share['name'],
                'share_to_all' => (int) $share['share_to_all'],
                'targets'      => $this->sharing->getTargets($share['idshare']),
                'subscribers'  => $this->sharing->getSubscriberCount($share['idshare']),
            );
        }
        return $result;
    }

    public function getAvailableShares(): array
    {
        $login = $this->checkAndGetLogin();
        $result = array();
        foreach ($this->sharing->getSharesAvailableTo($login) as $share) {
            if (!$this->shareAccess()->isVisibleToCurrentUser($share['owner_login'])) {
                continue;
            }
            if ($this->sharing->isSubscribed($share['idshare'], $login)) {
                continue;
            }
            // Skip shares whose source dashboard no longer exists (subscribing would fail anyway).
            // Not deleted here: the caller is not the owner.
            if (!$this->dashboardExists($share['owner_login'], (int) $share['iddashboard'])) {
                continue;
            }
            $result[] = array(
                'idshare' => (int) $share['idshare'],
                'owner'   => $share['owner_login'],
                'name'    => $share['name'],
            );
        }
        return $result;
    }

    /** Subscribe to a share: creates a linked, auto-synced dashboard. Returns the new idDashboard. */
    public function subscribeToShare(int $idShare): int
    {
        $login = $this->checkAndGetLogin();

        $available = false;
        foreach ($this->sharing->getSharesAvailableTo($login) as $share) {
            if ((int) $share['idshare'] === $idShare && $this->shareAccess()->isVisibleToCurrentUser($share['owner_login'])) {
                $available = true;
                break;
            }
        }
        if (!$available) {
            throw new \Exception('Share not available');
        }
        return $this->sharingService->subscribe($idShare, $login);
    }

    public function unsubscribe(int $idDashboard): void
    {
        Piwik::checkUserIsNotAnonymous();
        $this->sharingService->unsubscribe(Piwik::getCurrentUserLogin(), $idDashboard);
    }

    /** @return array{synced:bool} */
    public function syncLinkedDashboard(int $idDashboard): array
    {
        $login = $this->checkAndGetLogin();
        $link = $this->sharing->getLink($login, $idDashboard);
        $share = $link ? $this->sharing->getShare($link['idshare']) : false;
        if ($share && !$this->shareAccess()->isVisibleToCurrentUser($share['owner_login'])) {
            return array('synced' => false); // no website in common with the owner any more: keep the last copy as it is
        }
        return array('synced' => $this->sharingService->syncLink($login, $idDashboard));
    }

    /** @return array{linked:bool, owner?:string} */
    public function getLinkInfo(int $idDashboard): array
    {
        Piwik::checkUserIsNotAnonymous();
        $link = $this->sharing->getLink(Piwik::getCurrentUserLogin(), $idDashboard);
        if (!$link) {
            return array('linked' => false);
        }
        $share = $this->sharing->getShare($link['idshare']);
        return array('linked' => true, 'owner' => $share ? $share['owner_login'] : '');
    }

    /**
     * The current user's subscribed (linked) dashboards, with the source owner — used to mark
     * those dashboards in the dashboard tab menu.
     * @return array<array{iddashboard:int, owner:string}>
     */
    public function getMyLinkedDashboards(): array
    {
        Piwik::checkUserIsNotAnonymous();
        return $this->sharing->getSubscribedDashboards(Piwik::getCurrentUserLogin());
    }

    /** Users the current user can target when sharing (only those they may list; others get []). */
    public function getShareableUsers(): array
    {
        Piwik::checkUserIsNotAnonymous();
        $login = Piwik::getCurrentUserLogin();

        // Call the API class directly (a Request::processRequest sub-call mis-resolves the output
        // renderer in some contexts and returns nothing). getUsers() enforces the admin permission
        // itself, so a non-admin simply gets an empty list rather than an error.
        try {
            $users = UsersManagerAPI::getInstance()->getUsers();
        } catch (\Exception $e) {
            return array();
        }

        $result = array();
        foreach ($users as $user) {
            $u = $user['login'];
            if ($u !== $login && $u !== 'anonymous') {
                $result[] = $u;
            }
        }
        return $result;
    }

    /**
     * The answer to the feedback question at the end of the share dialog (templates/_feedback.twig). The Matomo server
     * sends it to Feinwerk (FeinwerkEmailReportsPro/docs/feedback-kit.md): plugin and Matomo version, question, vote,
     * browser family and interface language, nothing about the user, the website or its visitors. Nothing is sent with
     * feedback = 0 in [FeinwerkDashboardSharing] or with Matomo's internet features off; one vote per user and question
     * every 10 seconds. Store errors never reach the user.
     *
     * @param string $topic the question, see PluginFeedback::TOPICS
     * @param string $vote up or down
     * @return array{sent: bool} whether the store accepted the vote
     */
    public function sendFeedback(string $topic, string $vote): array
    {
        $login = $this->checkAndGetLogin();
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            throw new \Exception('FeinwerkDashboardSharing.sendFeedback only accepts POST requests.');
        }
        try {
            $payload = PluginFeedback::payload(
                $topic,
                $vote,
                $this->pluginVersion(),
                Version::VERSION,
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                $this->interfaceLanguage()
            );
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException($this->translate('FeinwerkDashboardSharing_ErrorFeedback'));
        }

        return ['sent' => $this->feedbackVotes()->cast($login, $payload, $this->feedbackEnabled())];
    }

    /** Test seam: the switches feedback = 0, Matomo's internet features and its test mode (PluginFeedback::enabled()). */
    protected function feedbackEnabled(): bool
    {
        return PluginFeedback::enabled();
    }

    /** Test seam: the installed version of this plugin. */
    protected function pluginVersion(): string
    {
        return (string) PluginManager::getInstance()->getLoadedPlugin(PluginFeedback::PLUGIN)->getVersion();
    }

    /** Test seam: the language of the user's interface. */
    protected function interfaceLanguage(): string
    {
        return (string) StaticContainer::get(Translator::class)->getCurrentLanguage();
    }

    /** Test seam: Matomo's translation. */
    protected function translate(string $key): string
    {
        return Piwik::translate($key);
    }

    /** Test seam: the vote counter with Matomo's options and the real store. */
    protected function feedbackVotes(): FeedbackVotes
    {
        return FeedbackVotes::create();
    }

    private function shareAccess(): ShareAccess
    {
        if ($this->shareAccess === null) {
            $this->shareAccess = new ShareAccess();
        }
        return $this->shareAccess;
    }

    /** The signed-in user's login; anonymous users get Matomo's no-access error. */
    protected function checkAndGetLogin(): string
    {
        Piwik::checkUserIsNotAnonymous();
        return Piwik::getCurrentUserLogin();
    }

    private function checkDashboardOwnedBy(string $login, int $idDashboard): void
    {
        if (!$this->dashboardExists($login, $idDashboard)) {
            throw new \Exception('Dashboard not found');
        }
    }

    /** True when $login still has a dashboard with this id (used to drop/skip dead shares). */
    private function dashboardExists(string $login, int $idDashboard): bool
    {
        $rows = $this->dashboardModel->getLayoutForUser($login, $idDashboard);
        return !empty($rows);
    }
}
