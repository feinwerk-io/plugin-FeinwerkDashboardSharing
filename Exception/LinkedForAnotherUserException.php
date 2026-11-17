<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\Exception;

use Piwik\Http\HttpCodeException;

/**
 * A subscription key (login, dashboard) that another Matomo user holds, because a table without the binary login
 * collation compares two logins as equal: the subscription is refused with HTTP 409, and the message names no login.
 */
class LinkedForAnotherUserException extends \Exception implements HttpCodeException
{
    public function __construct()
    {
        parent::__construct('This dashboard is already linked for another user.', 409);
    }
}
