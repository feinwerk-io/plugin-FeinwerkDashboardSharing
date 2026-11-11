<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit\Feedback;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\Dashboard\Model as DashboardModel;
use Piwik\Plugins\FeinwerkDashboardSharing\API;
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;
use Piwik\Plugins\FeinwerkDashboardSharing\Feedback\FeedbackVotes;
use Piwik\Plugins\FeinwerkDashboardSharing\Feedback\PluginFeedback;
use Piwik\Plugins\FeinwerkDashboardSharing\SharingService;

/**
 * The API method sendFeedback behind the thumbs in the share dialog: POST only, only the questions the plugin asks, and
 * what reaches the store. The access check, the plugin version, the language and the store are test doubles; no real
 * vote leaves the test.
 *
 * @group FeinwerkDashboardSharing
 */
class SendFeedbackTest extends TestCase
{
    private const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:144.0) Gecko/20100101 Firefox/144.0';

    /** @var list<string> JSON bodies the fake store received */
    private array $sent = [];
    private bool $enabled = true;

    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_USER_AGENT'] = self::FIREFOX;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_USER_AGENT']);
    }

    public function test_a_vote_reaches_the_store_without_the_login(): void
    {
        $this->assertSame(['sent' => true], $this->api()->sendFeedback('sharing', 'up'));

        $this->assertCount(1, $this->sent);
        $body = json_decode($this->sent[0], true);
        $this->assertSame('FeinwerkDashboardSharing', $body['plugin']);
        $this->assertSame('2.0.0-beta.4', $body['plugin_version']);
        $this->assertSame('sharing', $body['topic']);
        $this->assertSame('up', $body['vote']);
        $this->assertSame('Firefox 144', $body['browser']);
        $this->assertSame('de', $body['language']);
        $this->assertStringNotContainsString('anna', $this->sent[0]);
    }

    public function test_nothing_is_sent_when_feedback_is_switched_off(): void
    {
        $this->enabled = false;

        $this->assertSame(['sent' => false], $this->api()->sendFeedback('sharing', 'down'));
        $this->assertSame([], $this->sent);
    }

    public function test_only_post_requests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectExceptionMessage('only accepts POST requests');
        try {
            $this->api()->sendFeedback('sharing', 'up');
        } finally {
            $this->assertSame([], $this->sent);
        }
    }

    public function test_a_question_the_plugin_does_not_ask_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[FeinwerkDashboardSharing_ErrorFeedback]');
        try {
            $this->api()->sendFeedback('pricing', 'up');
        } finally {
            $this->assertSame([], $this->sent);
        }
    }

    public function test_a_vote_other_than_up_or_down_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->api()->sendFeedback('sharing', 'maybe');
    }

    private function api(): API
    {
        $test = $this;
        $votes = new FeedbackVotes(
            new PluginFeedback(function (...$args) use ($test) {
                $test->sent[] = $args[14];
                return ['status' => 202, 'data' => ''];
            }),
            fn (string $key) => false,
            function (string $key, string $value): void {
            },
            fn (): int => 1_800_000_000
        );

        return new class ($this->createMock(Sharing::class), $this->createMock(SharingService::class), $this->createMock(DashboardModel::class), $votes, $this->enabled) extends API {
            public function __construct($sharing, $service, $model, private FeedbackVotes $votes, private bool $on)
            {
                parent::__construct($sharing, $service, $model);
            }

            protected function checkAndGetLogin(): string
            {
                return 'anna';
            }

            protected function pluginVersion(): string
            {
                return '2.0.0-beta.4';
            }

            protected function interfaceLanguage(): string
            {
                return 'de';
            }

            protected function translate(string $key): string
            {
                return '[' . $key . ']';
            }

            protected function feedbackVotes(): FeedbackVotes
            {
                return $this->votes;
            }

            protected function feedbackEnabled(): bool
            {
                return $this->on;
            }
        };
    }
}
