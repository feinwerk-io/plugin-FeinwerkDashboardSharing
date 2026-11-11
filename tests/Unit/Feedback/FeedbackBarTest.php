<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit\Feedback;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\Feedback\FeedbackBar;
use Piwik\Plugins\FeinwerkDashboardSharing\Feedback\PluginFeedback;

/**
 * The question at the end of the share dialog and what the bar needs to show it.
 *
 * @group FeinwerkDashboardSharing
 */
class FeedbackBarTest extends TestCase
{
    public function testEveryQuestionHasATextAndIsAcceptedByTheApi(): void
    {
        $this->assertSame(PluginFeedback::TOPICS, array_keys(FeedbackBar::QUESTIONS));
        $this->assertSame('sharing', FeedbackBar::TOPIC_SHARING);
    }

    public function testVariablesOfTheTemplate(): void
    {
        $translate = fn (string $key): string => '[' . $key . ']';
        $this->assertSame([
            'topic' => 'sharing',
            'question' => '[FeinwerkDashboardSharing_FeedbackQuestionSharing]',
            'enabled' => true,
            'supportUrl' => PluginFeedback::supportUrl('de'),
            'version' => '1.0.0-beta.4',
        ], FeedbackBar::variables('sharing', true, 'de', '1.0.0-beta.4', $translate));
    }

    public function testSwitchedOffShowsOnlyTheSupportLink(): void
    {
        $variables = FeedbackBar::variables('sharing', false, 'en', '1.0.0', fn (string $key): string => $key);
        $this->assertFalse($variables['enabled']);
        $this->assertSame(PluginFeedback::supportUrl('en'), $variables['supportUrl']);
    }

    public function testUnknownTopicIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedbackBar::variables('pricing', true, 'en', '1.0.0', fn (string $key): string => $key);
    }

    /** Every text of the question exists in both languages, without en or em dashes (house rule). */
    public function testTextsInGermanAndEnglish(): void
    {
        $keys = array_merge(array_values(FeedbackBar::QUESTIONS), [
            'FeinwerkDashboardSharing_FeedbackYes',
            'FeinwerkDashboardSharing_FeedbackNo',
            'FeinwerkDashboardSharing_FeedbackThanks',
            'FeinwerkDashboardSharing_FeedbackThanksDown',
            'FeinwerkDashboardSharing_FeedbackPrivacy',
            'FeinwerkDashboardSharing_Support',
            'FeinwerkDashboardSharing_SupportTitle',
            'FeinwerkDashboardSharing_ErrorFeedback',
        ]);
        foreach (['en', 'de'] as $language) {
            $texts = json_decode((string) file_get_contents(__DIR__ . '/../../../lang/' . $language . '.json'), true);
            foreach ($keys as $key) {
                $text = $texts['FeinwerkDashboardSharing'][substr($key, strlen('FeinwerkDashboardSharing_'))] ?? '';
                $this->assertNotSame('', $text, $language . ' ' . $key);
                $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $text, $language . ' ' . $key);
            }
        }
    }
}
