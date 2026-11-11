<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit\Feedback;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\Feedback\FeedbackVotes;
use Piwik\Plugins\FeinwerkDashboardSharing\Feedback\PluginFeedback;


/**
 * A vote of the API method sendFeedback: switched off, throttled per user and question, store failures. The store is a
 * test double; no real vote leaves the test.
 */
class FeedbackVotesTest extends TestCase
{
    /** @var array<string, string> */
    private array $options = [];
    private int $now = 1_800_000_000;
    /** @var list<string> JSON bodies the fake store received */
    private array $sent = [];
    private int $status = 202;

    private function votes(): FeedbackVotes
    {
        $transport = function (...$args) {
            $this->sent[] = $args[14];
            return ['status' => $this->status, 'data' => ''];
        };

        return new FeedbackVotes(
            new PluginFeedback($transport),
            fn (string $key) => $this->options[$key] ?? false,
            function (string $key, string $value): void {
                $this->options[$key] = $value;
            },
            fn (): int => $this->now
        );
    }

    private static function payload(string $topic = 'sharing', string $vote = 'up'): array
    {
        return PluginFeedback::payload($topic, $vote, '6.0.0-beta.1', '6.0.0-rc1', '', 'de');
    }

    /** A second question (this plugin asks one; the throttle is per question all the same). */
    private static function otherQuestion(string $vote = 'up'): array
    {
        return array_replace(self::payload('sharing', $vote), ['topic' => 'other']);
    }

    public function testAVoteGoesToTheStore(): void
    {
        $this->assertTrue($this->votes()->cast('anna', self::payload(), true));
        $this->assertCount(1, $this->sent);
        $this->assertSame(self::payload(), json_decode($this->sent[0], true));
        $this->assertSame((string) $this->now, $this->options[PluginFeedback::throttleOption('anna', 'sharing')]);
    }

    public function testNothingIsSentWhenFeedbackIsSwitchedOff(): void
    {
        $this->assertFalse($this->votes()->cast('anna', self::payload(), false));
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->options, 'no throttle written either');
    }

    public function testOneVotePerUserAndQuestionEveryTenSeconds(): void
    {
        $votes = $this->votes();
        $this->assertTrue($votes->cast('anna', self::payload('sharing', 'up'), true));
        $this->now += 9;
        $this->assertFalse($votes->cast('anna', self::payload('sharing', 'down'), true), 'same question, 9 s later');
        $this->assertTrue($votes->cast('anna', self::otherQuestion('down'), true), 'another question on the page');
        $this->assertTrue($votes->cast('bert', self::payload('sharing', 'down'), true), 'another user');
        $this->now += 1;
        $this->assertTrue($votes->cast('anna', self::payload('sharing', 'down'), true), '10 s later');
        $this->assertCount(4, $this->sent);
    }

    /** All users and questions together: at most SERVER_CAP votes a minute leave this Matomo. */
    public function testTheServerCapCountsAllUsersAndQuestions(): void
    {
        $votes = $this->votes();
        for ($i = 0; $i < PluginFeedback::SERVER_CAP; $i++) {
            $this->assertTrue($votes->cast('user' . $i, self::payload(), true));
        }
        $this->assertFalse($votes->cast('another', self::otherQuestion(), true));
        $this->assertCount(PluginFeedback::SERVER_CAP, $this->sent);
        $this->now += 60;
        $this->assertTrue($votes->cast('another', self::otherQuestion(), true), 'the next minute');
    }

    /** A vote stopped by the cap does not use up the user's ten seconds: both are stored only when sending. */
    public function testACappedVoteLeavesTheUsersThrottleAlone(): void
    {
        $this->options[PluginFeedback::SERVER_OPTION] = intdiv($this->now, 60) . ':' . PluginFeedback::SERVER_CAP;

        $this->assertFalse($this->votes()->cast('anna', self::payload(), true));
        $this->assertArrayNotHasKey(PluginFeedback::throttleOption('anna', 'sharing'), $this->options);
    }

    public function testAStoreThatRejectsTheVoteGivesNoError(): void
    {
        $this->status = 429;
        $this->assertFalse($this->votes()->cast('anna', self::payload(), true));
        $this->assertCount(1, $this->sent, 'sent once, no retry');
    }
}
