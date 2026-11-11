<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

use Piwik\Piwik;
use Piwik\Plugins\FeinwerkDashboardSharing\Feedback\FeedbackBar;
use Piwik\Plugins\FeinwerkDashboardSharing\Promo\PromoLine;

/**
 * What stands at the end of the share dialog, the plugin's own view: the feedback question with the support link
 * (Feedback\FeedbackBar) and, for admins and super users, the tip line of the Feinwerk promotion kit (Promo\PromoLine).
 * The dialog is built in javascripts/dashboardSharing.js and loads this block through the controller action
 * dialogNotes. Never on the dashboard itself, which subscribers see as well (docs/promotions.md).
 */
final class DialogNotes
{
    /** Pure: the parts in one block, '' when there is none. */
    public static function wrap(string $feedback, string $tipLine): string
    {
        $html = $feedback . $tipLine;

        return $html === '' ? '' : '<div class="fwsh-notes">' . $html . '</div>';
    }

    /**
     * The notes for the current user; '' for anonymous users. A failing part is left out, never breaks the dialog.
     *
     * @param int $idSite the website the dashboard shows (the tip line names ecommerce products only for shops)
     */
    public static function html(int $idSite): string
    {
        try {
            if (Piwik::isUserIsAnonymous()) {
                return '';
            }
        } catch (\Throwable $e) {
            return '';
        }

        return self::wrap(self::feedback(), self::tipLine($idSite));
    }

    private static function feedback(): string
    {
        try {
            return FeedbackBar::render(FeedbackBar::current(FeedbackBar::TOPIC_SHARING));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function tipLine(int $idSite): string
    {
        try {
            $promo = PromoLine::current($idSite);

            return $promo === null ? '' : PromoLine::render($promo);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
