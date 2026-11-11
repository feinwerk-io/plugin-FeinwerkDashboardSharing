<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\Feedback;

use Piwik\Option;

/**
 * One vote of the API method sendFeedback: nothing when feedback is switched off, at most one vote per user and
 * question every 10 seconds (Matomo option per user and question, PluginFeedback::throttleOption()) and at most
 * PluginFeedback::SERVER_CAP votes a minute for the whole Matomo (PluginFeedback::SERVER_OPTION), then sent once. Both
 * options are written only when the vote is sent.
 */
final class FeedbackVotes
{
    public const THROTTLE_SECONDS = 10;

    /** @var callable(string): (string|false) */
    private $readOption;
    /** @var callable(string, string): void */
    private $writeOption;
    /** @var callable(): int */
    private $now;

    public function __construct(private PluginFeedback $sender, callable $readOption, callable $writeOption, callable $now)
    {
        $this->readOption = $readOption;
        $this->writeOption = $writeOption;
        $this->now = $now;
    }

    /** With Matomo's options, the clock and the real store. */
    public static function create(): self
    {
        return new self(
            new PluginFeedback(),
            [Option::class, 'get'],
            fn (string $key, string $value) => Option::set($key, $value),
            'time'
        );
    }

    /**
     * @param array<string, ?string> $payload PluginFeedback::payload()
     * @param bool $enabled PluginFeedback::enabled()
     * @return bool whether the store accepted the vote
     */
    public function cast(string $login, array $payload, bool $enabled): bool
    {
        if (!$enabled) {
            return false;
        }
        $key = PluginFeedback::throttleOption($login, (string) $payload['topic']);
        $now = (int) ($this->now)();
        if ($now - (int) ($this->readOption)($key) < self::THROTTLE_SECONDS) {
            return false;
        }
        $stored = ($this->readOption)(PluginFeedback::SERVER_OPTION);
        $server = PluginFeedback::countVote($stored === false ? null : (string) $stored, $now);
        if ($server === null) {
            return false; // this Matomo sent SERVER_CAP votes this minute
        }
        ($this->writeOption)($key, (string) $now);
        ($this->writeOption)(PluginFeedback::SERVER_OPTION, $server);

        return $this->sender->send($payload);
    }
}
