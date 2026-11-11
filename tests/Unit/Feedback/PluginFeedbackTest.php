<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit\Feedback;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\Feedback\PluginFeedback;


/**
 * Feedback thumbs at the end of the share dialog: what goes to Feinwerk (nothing personal), how it is sent and
 * where the support link leads. No test sends a real vote: the transport is always a test double.
 */
class PluginFeedbackTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_VIA']);
    }

    public function testPayloadFollowsTheStoreContract(): void
    {
        $this->assertSame([
            'plugin' => 'FeinwerkDashboardSharing',
            'plugin_version' => '6.0.0-beta.1',
            'matomo_version' => '6.0.0-rc1',
            'topic' => 'sharing',
            'vote' => 'down',
            'browser' => 'Chrome 141',
            'language' => 'de',
        ], PluginFeedback::payload('sharing', 'down', '6.0.0-beta.1', '6.0.0-rc1', self::CHROME, 'de'));
    }

    public function testTopicAndVoteAreTrimmed(): void
    {
        // Matomo 6 no longer trims request parameters before they reach the API method
        $payload = PluginFeedback::payload(" sharing\n", ' up ', '6.0.0', '6.0.0', '', 'en');
        $this->assertSame('sharing', $payload['topic']);
        $this->assertSame('up', $payload['vote']);
    }

    public function testEveryQuestionOfThePluginIsAccepted(): void
    {
        $this->assertSame(['sharing'], PluginFeedback::TOPICS);
        foreach (PluginFeedback::TOPICS as $topic) {
            $this->assertMatchesRegularExpression('/^[a-z_]{1,30}$/', $topic, 'store pattern of topic');
            $this->assertSame($topic, PluginFeedback::payload($topic, 'up', '1', '6', '', 'en')['topic']);
        }
    }

    public function testOnlyTheQuestionsThePluginAsks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluginFeedback::payload('pricing', 'up', '6.0.0', '6.0.0', '', 'en');
    }

    public function testOnlyUpOrDown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluginFeedback::payload('sharing', 'maybe', '6.0.0', '6.0.0', '', 'en');
    }

    public function testUnknownBrowserAndLanguageAreNull(): void
    {
        $payload = PluginFeedback::payload('sharing', 'up', '6.0.0', '6.0.0', 'curl/8.5.0', '');
        $this->assertNull($payload['browser']);
        $this->assertNull($payload['language']);
    }

    public function testVersionsAndLanguageAreCutToTheStoreLimits(): void
    {
        $payload = PluginFeedback::payload('sharing', 'up', str_repeat('1', 40), str_repeat('6', 40), '', 'de"><script>alert(1)</script>');
        $this->assertSame(30, mb_strlen($payload['plugin_version']));
        $this->assertSame(30, mb_strlen($payload['matomo_version']));
        $this->assertMatchesRegularExpression('/^[A-Za-z_-]{1,10}$/', $payload['language']);
    }

    public function testPluginNameMatchesTheStorePattern(): void
    {
        $this->assertMatchesRegularExpression('/^Feinwerk[A-Za-z0-9]{3,52}$/', PluginFeedback::payload('sharing', 'up', '1', '6', '', 'en')['plugin']);
    }

    public function testBrowserIsFamilyAndMajorVersionOnly(): void
    {
        $this->assertSame('Chrome 141', PluginFeedback::browser(self::CHROME));
        $this->assertSame('Edge 140', PluginFeedback::browser('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.3485.54'));
        $this->assertSame('Firefox 143', PluginFeedback::browser('Mozilla/5.0 (X11; Linux x86_64; rv:143.0) Gecko/20100101 Firefox/143.0'));
        $this->assertSame('Safari 18', PluginFeedback::browser('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Safari/605.1.15'));
        $this->assertSame('Opera 122', PluginFeedback::browser('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 OPR/122.0.0.0'));
        $this->assertSame('', PluginFeedback::browser('curl/8.5.0'));
    }

    public function testSupportOpensTheContactFormWithThePluginsTopic(): void
    {
        $tracking = 'mtm_campaign=plugin-support&mtm_source=FeinwerkDashboardSharing';
        $this->assertSame('https://feinwerk.io/de/kontakt?service=plugins&' . $tracking, PluginFeedback::supportUrl('de'));
        $this->assertSame('https://feinwerk.io/en/contact?service=plugins&' . $tracking, PluginFeedback::supportUrl('en'));
        $this->assertSame('https://feinwerk.io/en/contact?service=plugins&' . $tracking, PluginFeedback::supportUrl('fr'));
    }

    public function testFeedbackCanBeSwitchedOffInTheConfig(): void
    {
        $this->assertTrue(PluginFeedback::enabledBy([]));
        $this->assertTrue(PluginFeedback::enabledBy(['feedback' => '1']));
        $this->assertTrue(PluginFeedback::enabledBy(['feedback' => 1]));
        $this->assertFalse(PluginFeedback::enabledBy(['feedback' => '0']));
        $this->assertFalse(PluginFeedback::enabledBy(['feedback' => 0]));
        $this->assertFalse(PluginFeedback::enabledBy(['feedback' => ' 0 ']));
    }

    /** Matomo's INI reader turns off, no and false into PHP false; an empty value counts as off as well (feedback kit). */
    public function testEverySpellingOfOffSwitchesItOff(): void
    {
        foreach ([false, null, '', ' ', 'off', 'Off', 'no', 'false', 'FALSE'] as $value) {
            $this->assertFalse(PluginFeedback::enabledBy(['feedback' => $value]), var_export($value, true));
        }
        foreach ([true, '1', 'on', 'yes'] as $value) {
            $this->assertTrue(PluginFeedback::enabledBy(['feedback' => $value]), var_export($value, true));
        }
    }

    /** Test runs and sandboxes never send a vote (on 2026-11-05 a sandbox vote reached the production store). */
    public function testNothingIsSentInTestMode(): void
    {
        $this->assertFalse(PluginFeedback::enabledBy([], true, true));
        $this->assertFalse(PluginFeedback::enabledBy(['feedback' => '1'], true, true));
        $this->assertTrue(PluginFeedback::enabledBy([], true, false));
    }

    /** The class refuses too: with Matomo's real HTTP client nothing leaves a test run, a test double may send. */
    public function testTheRealClientNeverSendsInTestMode(): void
    {
        if (!defined('PIWIK_TEST_MODE')) {
            define('PIWIK_TEST_MODE', true);
        }
        $this->assertFalse((new PluginFeedback())->isEnabled());
        $this->assertFalse((new PluginFeedback())->send(['plugin' => 'FeinwerkDashboardSharing']));
        $this->assertTrue((new PluginFeedback(fn (...$args) => ['status' => 202]))->isEnabled());
    }

    public function testTheServerSendsAtMostTwentyVotesAMinute(): void
    {
        $now = 1_800_000_000; // 30_000_000 minutes exactly
        $stored = null;
        for ($i = 1; $i <= PluginFeedback::SERVER_CAP; $i++) {
            $stored = PluginFeedback::countVote($stored, $now);
            $this->assertSame('30000000:' . $i, $stored);
        }
        $this->assertNull(PluginFeedback::countVote($stored, $now + 59), 'the 21st vote of the minute');
        $this->assertSame('30000001:1', PluginFeedback::countVote($stored, $now + 60), 'a new minute');
        $this->assertSame('30000000:1', PluginFeedback::countVote('garbage', $now));
        $this->assertSame('fwsh_feedback.server', PluginFeedback::SERVER_OPTION);
        $this->assertStringStartsNotWith(PluginFeedback::userOptionPrefix('server'), PluginFeedback::SERVER_OPTION);
    }

    public function testNothingIsSentWhenMatomoHasItsInternetFeaturesOff(): void
    {
        // [General] enable_internet_features = 0: Matomo's HTTP client does not check it, so the plugin must
        $this->assertFalse(PluginFeedback::enabledBy([], false));
        $this->assertFalse(PluginFeedback::enabledBy(['feedback' => '1'], false));
    }

    public function testEachQuestionHasItsOwnThrottle(): void
    {
        $channels = PluginFeedback::throttleOption('anna', 'sharing');
        $this->assertNotSame($channels, PluginFeedback::throttleOption('anna', 'manage'), 'a second question on the page still counts');
        $this->assertNotSame($channels, PluginFeedback::throttleOption('bert', 'sharing'));
        $this->assertStringStartsWith(PluginFeedback::userOptionPrefix('anna'), $channels, 'deleted with the user');
        $this->assertStringNotContainsString('anna', $channels, 'the login is not stored in plain text');
    }

    public function testThrottlesAreRemovedOnUninstall(): void
    {
        // FeinwerkDashboardSharing::uninstall() deletes every option starting with "fwsh_"
        $this->assertStringStartsWith('fwsh_', PluginFeedback::THROTTLE_OPTION_PREFIX);
    }

    public function testTheStoreOfTheConnectorIsUsedWhenThereIsOne(): void
    {
        $this->assertSame('https://store.example/api/v1/feedback', PluginFeedback::endpoint(FakeConnectorFactory::class));
    }

    public function testFeinwerksStoreWithoutTheConnector(): void
    {
        $this->assertSame('https://plugins.feinwerk.io/api/v1/feedback', PluginFeedback::endpoint('\\Piwik\\Plugins\\NoSuchPlugin\\MarketplaceClientFactory'));
        $this->assertSame('https://plugins.feinwerk.io/api/v1/feedback', PluginFeedback::endpoint(BrokenConnectorFactory::class), 'a failing connector');
        $this->assertSame('https://plugins.feinwerk.io/api/v1/feedback', PluginFeedback::endpoint(EmptyConnectorFactory::class));
    }

    public function testSendsTheVoteAsJsonByPostWithAShortTimeout(): void
    {
        $seen = [];
        $transport = function (...$args) use (&$seen) {
            $seen = $args;
            return ['status' => 202, 'data' => '{"ok":true}'];
        };
        $payload = PluginFeedback::payload('sharing', 'up', '6.0.0-beta.1', '6.0.0-rc1', self::CHROME, 'en');
        $this->assertTrue((new PluginFeedback($transport))->send($payload));
        $this->assertSame(3, $seen[2], 'timeout in seconds, the same in every plugin');
        $this->assertSame('POST', $seen[11]);
        $this->assertSame($payload, json_decode($seen[14], true));
        $this->assertContains('Content-Type: application/json', $seen[15]);
        $this->assertStringEndsWith('/api/v1/feedback', $seen[1]);
    }

    public function testSendsNoBrowserDetailsInHeaders(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = self::CHROME;
        $_SERVER['HTTP_VIA'] = '1.1 corporate-proxy.example';
        $seen = [];
        $transport = function (...$args) use (&$seen) {
            $seen = ['userAgent' => $args[3], 'via' => $_SERVER['HTTP_VIA'] ?? null];
            return ['status' => 202, 'data' => '{"ok":true}'];
        };
        $this->assertTrue((new PluginFeedback($transport))->send(['plugin' => 'FeinwerkDashboardSharing']));
        $this->assertSame(PluginFeedback::USER_AGENT, $seen['userAgent']);
        $this->assertStringNotContainsString('Mozilla', $seen['userAgent']);
        $this->assertNull($seen['via'], 'the incoming proxy chain must not go into the Via header');
        $this->assertSame('1.1 corporate-proxy.example', $_SERVER['HTTP_VIA'], 'restored after the request');
    }

    public function testARejectedVoteIsNotSentAndNoError(): void
    {
        foreach ([422, 429, 500, 0] as $status) {
            $transport = fn (...$args) => ['status' => $status, 'data' => ''];
            $this->assertFalse((new PluginFeedback($transport))->send(['plugin' => 'FeinwerkDashboardSharing']), (string) $status);
        }
    }

    public function testAnUnreachableStoreIsSilent(): void
    {
        $_SERVER['HTTP_VIA'] = '1.1 proxy.example';
        $exception = function (...$args) {
            throw new \Exception('timeout');
        };
        $error = function (...$args) {
            throw new \TypeError('broken transport');
        };
        $this->assertFalse((new PluginFeedback($exception))->send(['plugin' => 'FeinwerkDashboardSharing']));
        $this->assertFalse((new PluginFeedback($error))->send(['plugin' => 'FeinwerkDashboardSharing']));
        $this->assertSame('1.1 proxy.example', $_SERVER['HTTP_VIA'], 'restored after a failure');
    }
}

/** Test doubles of the Marketplace Connector's MarketplaceClientFactory. */
class FakeConnectorFactory
{
    public static function url(): string
    {
        return 'https://store.example/';
    }
}

class BrokenConnectorFactory
{
    public static function url(): string
    {
        throw new \Error('settings missing');
    }
}

class EmptyConnectorFactory
{
    public static function url(): string
    {
        return '';
    }
}
