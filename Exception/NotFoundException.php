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
 * A dashboard or share that does not exist or is not offered to the current user (the answer is the same, so it does not
 * tell the two apart): the API answers with HTTP 404. Core's ThingNotFoundException exists only from Matomo 5.14.
 */
class NotFoundException extends \Exception implements HttpCodeException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 404);
    }
}
