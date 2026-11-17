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
 * Invalid input to the plugin's API: the API answers with HTTP 400. Not core's BadRequestException, which in Matomo
 * 5.0.0 drops its code (the response would stay 200).
 */
class InvalidRequestException extends \Exception implements HttpCodeException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 400);
    }
}
