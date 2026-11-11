<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\Feedback;

use Piwik\Container\StaticContainer;
use Piwik\Piwik;
use Piwik\Plugin\Manager as PluginManager;
use Piwik\Translation\Translator;
use Piwik\View;

/**
 * The question with thumbs up and down and the support link (templates/_feedback.twig, behaviour in
 * javascripts/feedback.js) at the end of the share dialog (DialogNotes). Switched off (config or Matomo's internet
 * features), only the support link remains.
 */
final class FeedbackBar
{
    public const TOPIC_SHARING = 'sharing';

    /** question (store "topic") => its text; the same topics as PluginFeedback::TOPICS */
    public const QUESTIONS = [
        self::TOPIC_SHARING => 'FeinwerkDashboardSharing_FeedbackQuestionSharing',
    ];

    /**
     * Variables of templates/_feedback.twig. Pure.
     *
     * @param callable(string): string $translate
     * @return array{topic: string, question: string, enabled: bool, supportUrl: string, version: string}
     */
    public static function variables(string $topic, bool $enabled, string $language, string $version, callable $translate): array
    {
        if (!isset(self::QUESTIONS[$topic])) {
            throw new \InvalidArgumentException('topic');
        }

        return [
            'topic' => $topic,
            'question' => $translate(self::QUESTIONS[$topic]),
            'enabled' => $enabled,
            'supportUrl' => PluginFeedback::supportUrl($language),
            'version' => $version,
        ];
    }

    /** Variables for the current request: Matomo's switches, the user's language, the installed plugin version. */
    public static function current(string $topic): array
    {
        return self::variables(
            $topic,
            PluginFeedback::enabled(),
            (string) StaticContainer::get(Translator::class)->getCurrentLanguage(),
            (string) PluginManager::getInstance()->getLoadedPlugin(PluginFeedback::PLUGIN)->getVersion(),
            [Piwik::class, 'translate']
        );
    }

    /** @param array<string, mixed> $feedback see variables() */
    public static function render(array $feedback): string
    {
        $view = new View('@FeinwerkDashboardSharing/_feedback');
        $view->feedback = $feedback;

        return $view->render();
    }
}
