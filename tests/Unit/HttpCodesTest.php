<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Http\HttpCodeException;
use Piwik\Plugins\FeinwerkDashboardSharing\Exception\InvalidRequestException;
use Piwik\Plugins\FeinwerkDashboardSharing\Exception\LinkedForAnotherUserException;
use Piwik\Plugins\FeinwerkDashboardSharing\Exception\NotFoundException;

/**
 * Matomo answers an API error with the HTTP status of an HttpCodeException: 400 for invalid input, 404 for a dashboard or
 * share that is missing or not offered, 409 for a subscription key another user holds.
 *
 * @group FeinwerkDashboardSharing
 */
class HttpCodesTest extends TestCase
{
    public function test_eachErrorCarriesItsHttpStatus()
    {
        $errors = array(
            400 => new InvalidRequestException('invalid'),
            404 => new NotFoundException('missing'),
            409 => new LinkedForAnotherUserException(),
        );
        foreach ($errors as $status => $error) {
            $this->assertInstanceOf(HttpCodeException::class, $error);
            $this->assertSame($status, $error->getCode());
        }
    }
}
