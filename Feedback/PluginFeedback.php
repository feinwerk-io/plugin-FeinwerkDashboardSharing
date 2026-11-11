<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\Feedback;

use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Http;
use Piwik\SettingsPiwik;
use Psr\Log\LoggerInterface;

/**
 * Feedback thumbs at the end of the share dialog and the support link next to them: the server side of
 * the Feinwerk feedback kit (FeinwerkEmailReportsPro/docs/feedback-kit.md). The vote goes from the Matomo server to
 * Feinwerk's store (POST /api/v1/feedback, privacy policy clause 7d): plugin, versions, question, vote, browser family
 * and interface language, nothing about the user, the website or its visitors. config.ini
 * [FeinwerkDashboardSharing] feedback = 0 switches it off.
 */
class PluginFeedback
{
    public const PLUGIN = 'FeinwerkDashboardSharing';
    /** the questions (store "topic") this plugin asks, see FeedbackBar::QUESTIONS; the API accepts only these */
    public const TOPICS = ['sharing'];
    public const DEFAULT_STORE = 'https://plugins.feinwerk.io';
    private const TIMEOUT = 3; // seconds; the same in every plugin (feedback kit): a hanging store must not hold up Matomo
    private const CONNECTOR_FACTORY = '\\Piwik\\Plugins\\FeinwerkMarketplaceConnector\\MarketplaceClientFactory';
    /**
     * Sent instead of the user's browser string: without it Matomo's HTTP client forwards the browser's full
     * User-Agent (and puts it into the Via header), which the privacy line under the thumbs does not cover.
     */
    public const USER_AGENT = 'Feinwerk-Feedback/1.0';
    /**
     * per user and question: time of the last vote (one vote every 10 seconds, so a second question on the same page
     * still counts); deleted with the user and on uninstall (FeinwerkDashboardSharing::uninstall())
     */
    public const THROTTLE_OPTION_PREFIX = 'fwsh_feedback.';

    /** all throttle options of one user start with this (the login only as md5) */
    public static function userOptionPrefix(string $login): string
    {
        return self::THROTTLE_OPTION_PREFIX . md5($login) . '.';
    }

    public static function throttleOption(string $login, string $topic): string
    {
        return self::userOptionPrefix($login) . $topic;
    }

    /** @var callable Http::sendHttpRequestBy or a test double with the same arguments */
    private $transport;

    /** true for Matomo's HTTP client, false for a test double */
    private bool $realClient;

    public function __construct(?callable $transport = null)
    {
        $this->realClient = $transport === null;
        $this->transport = $transport ?? [Http::class, 'sendHttpRequestBy'];
    }

    /**
     * The request body of the store contract (unknown browser or language: null). Topic and vote arrive untrimmed
     * from Matomo 6's request parsing and are trimmed here.
     *
     * @return array<string, ?string>
     * @throws \InvalidArgumentException with the field name for a question the plugin does not ask or a vote other
     *                                   than up and down
     */
    public static function payload(string $topic, string $vote, string $pluginVersion, string $matomoVersion, string $userAgent, string $language): array
    {
        $topic = trim($topic);
        $vote = trim($vote);
        if (!in_array($topic, self::TOPICS, true)) {
            throw new \InvalidArgumentException('topic');
        }
        if (!in_array($vote, ['up', 'down'], true)) {
            throw new \InvalidArgumentException('vote');
        }
        $browser = self::browser($userAgent);
        $language = mb_substr((string) preg_replace('/[^A-Za-z_-]/', '', $language), 0, 10);

        return [
            'plugin' => self::PLUGIN,
            'plugin_version' => mb_substr($pluginVersion, 0, 30),
            'matomo_version' => mb_substr($matomoVersion, 0, 30),
            'topic' => $topic,
            'vote' => $vote,
            'browser' => $browser !== '' ? $browser : null,
            'language' => $language !== '' ? $language : null,
        ];
    }

    /** Browser family and major version ("Chrome 141"), empty when unknown. Order matters: Edge and Opera also say Chrome. */
    public static function browser(string $userAgent): string
    {
        $patterns = [
            'Edge' => '/\bEdg(?:e|A|iOS)?\/(\d+)/',
            'Opera' => '/\bOPR\/(\d+)/',
            'Firefox' => '/\bFirefox\/(\d+)/',
            'Chrome' => '/\b(?:Chrome|CriOS)\/(\d+)/',
            'Safari' => '/\bVersion\/(\d+)[.\d]* (?:Mobile\/\S+ )?Safari\//',
        ];
        foreach ($patterns as $family => $pattern) {
            if (preg_match($pattern, $userAgent, $m)) {
                return $family . ' ' . $m[1];
            }
        }

        return '';
    }

    /** The Feinwerk contact form with the topic "Plugins" preselected, German or English by the Matomo language. */
    public static function supportUrl(string $language): string
    {
        $page = $language === 'de' ? 'de/kontakt' : 'en/contact';

        return 'https://feinwerk.io/' . $page . '?service=plugins&mtm_campaign=plugin-support&mtm_source=' . self::PLUGIN;
    }

    public static function enabled(): bool
    {
        $section = Config::getInstance()->{self::PLUGIN};
        $testMode = defined('PIWIK_TEST_MODE') && PIWIK_TEST_MODE;

        return self::enabledBy(is_array($section) ? $section : [], SettingsPiwik::isInternetEnabled(), $testMode);
    }

    /**
     * @param array<string, mixed> $section the plugin's config.ini section
     * @param bool $internetEnabled Matomo's [General] enable_internet_features: its HTTP client does not check it, so
     *                              with it off nothing may be sent
     * @param bool $testMode PIWIK_TEST_MODE (Matomo's test framework): its runs never send a vote; fw-sandbox does not
     *                       define it, sandboxes rely on feedback = 0
     */
    public static function enabledBy(array $section, bool $internetEnabled = true, bool $testMode = false): bool
    {
        if (!$internetEnabled || $testMode) {
            return false;
        }
        if (!array_key_exists('feedback', $section)) {
            return true;
        }
        // Matomo's INI reader turns off, no and false into PHP false; an empty value counts as off as well.
        $value = $section['feedback'];

        return $value !== false && $value !== null
            && !in_array(strtolower(trim((string) $value)), ['0', '', 'off', 'no', 'false'], true);
    }

    /** Votes this Matomo sends to the store per minute at most, all users and questions together (feedback kit). */
    public const SERVER_CAP = 20;
    public const SERVER_OPTION = self::THROTTLE_OPTION_PREFIX . 'server';

    /**
     * Counts a vote against the server's cap; $stored is the SERVER_OPTION value ("minute:count").
     *
     * @return string|null the value to store, null when the cap of this minute is reached
     */
    public static function countVote(?string $stored, int $now, int $cap = self::SERVER_CAP): ?string
    {
        $minute = intdiv($now, 60);
        [$was, $count] = array_map('intval', explode(':', (string) $stored) + [0, 0]);
        if ($was !== $minute) {
            $count = 0;
        }

        return $count < $cap ? $minute . ':' . ($count + 1) : null;
    }

    /**
     * The store the connector talks to (a test store too), else Feinwerk's store.
     *
     * @param string $factoryClass the connector's factory; another class only in tests
     */
    public static function endpoint(string $factoryClass = self::CONNECTOR_FACTORY): string
    {
        $base = '';
        try {
            if (class_exists($factoryClass) && method_exists($factoryClass, 'url')) {
                $base = (string) $factoryClass::url();
            }
        } catch (\Throwable $e) {
            // an older or broken connector (e.g. a missing setting is a PHP Error): Feinwerk's store
            $base = '';
        }

        return rtrim($base !== '' ? $base : self::DEFAULT_STORE, '/') . '/api/v1/feedback';
    }

    /**
     * Sends the vote once (no retry); failures (store unreachable or refusing, endpoint missing) are logged on info
     * level and never reach the user.
     *
     * @param array<string, ?string> $payload see payload()
     */
    /**
     * Whether this instance may send: never with Matomo's real HTTP client while Matomo's test framework runs
     * (PIWIK_TEST_MODE), so no test can reach the store, even one that calls send() directly. A test double may.
     */
    public function isEnabled(): bool
    {
        return !$this->realClient || !(defined('PIWIK_TEST_MODE') && PIWIK_TEST_MODE);
    }

    public function send(array $payload): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        // Matomo's client also copies an incoming proxy chain (HTTP_VIA) into its Via header: leave it out.
        $via = $_SERVER['HTTP_VIA'] ?? null;
        unset($_SERVER['HTTP_VIA']);
        try {
            $response = ($this->transport)(
                $this->realClient ? Http::getTransportMethod() : 'curl',
                self::endpoint(),
                self::TIMEOUT,
                self::USER_AGENT,
                null,
                null,
                0,
                false,
                false,
                false,
                true,
                'POST',
                null,
                null,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ['Content-Type: application/json', 'Accept: application/json']
            );
        } catch (\Throwable $e) {
            self::log('feedback not sent: ' . $e->getMessage());

            return false;
        } finally {
            if ($via !== null) {
                $_SERVER['HTTP_VIA'] = $via;
            }
        }
        $status = is_array($response) ? (int) ($response['status'] ?? 0) : 0;
        if ($status < 200 || $status >= 300) {
            self::log('feedback not accepted (HTTP ' . $status . ')');

            return false;
        }

        return true;
    }

    private static function log(string $message): void
    {
        try {
            StaticContainer::get(LoggerInterface::class)->info(self::PLUGIN . ': ' . $message);
        } catch (\Throwable $e) {
            // no logger (e.g. outside a running Matomo): the failure stays silent
        }
    }
}
