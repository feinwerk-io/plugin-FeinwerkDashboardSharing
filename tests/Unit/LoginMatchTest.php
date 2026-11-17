<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\LoginMatch;

/**
 * Rows found by login in the plugin's tables are checked again exactly: the tables' collation can treat two different
 * Matomo users as one ("Anna" and "anna", or "strasse" and "straße" in some collations).
 *
 * @group FeinwerkDashboardSharing
 */
class LoginMatchTest extends TestCase
{
    /** Matomo's user lookup in this test: the users "anna" and "Strasse" exist, "straße" is a third user. */
    private static function users(): callable
    {
        return static function (string $login): string {
            $known = ['anna' => 'anna', 'Anna' => 'anna', 'ANNA' => 'anna', 'strasse' => 'Strasse', 'Strasse' => 'Strasse', 'straße' => 'straße'];

            return $known[$login] ?? '';
        };
    }

    public function test_owner_and_subscriber_logins_only_when_exactly_equal(): void
    {
        $this->assertTrue(LoginMatch::isSame('anna', 'anna'));
        $this->assertFalse(LoginMatch::isSame('Anna', 'anna'));
        $this->assertFalse(LoginMatch::isSame('strasse', 'straße'));
    }

    public function test_a_target_in_another_case_counts_when_matomo_resolves_it_to_the_user(): void
    {
        $this->assertTrue(LoginMatch::targetIs('anna', 'anna', self::users()), 'exact');
        $this->assertTrue(LoginMatch::targetIs('Anna', 'anna', self::users()), 'typed by the owner in another case');
    }

    public function test_a_target_that_belongs_to_another_user_does_not_count(): void
    {
        $this->assertFalse(LoginMatch::targetIs('strasse', 'straße', self::users()), 'another existing user');
        $this->assertFalse(LoginMatch::targetIs('bert', 'anna', self::users()), 'no such user');
        $this->assertFalse(LoginMatch::targetIs('bert', '', self::users()), 'an empty login never matches through the lookup');
    }

    public function test_rows_removed_with_a_deleted_user(): void
    {
        $gone = static function (string $login): string {
            // after the deletion: "anna" is gone, "Strasse" and "straße" still exist
            $known = ['strasse' => 'Strasse', 'Strasse' => 'Strasse', 'straße' => 'straße'];

            return $known[$login] ?? '';
        };
        $this->assertTrue(LoginMatch::goesWithDeletedUser('anna', 'anna', '', $gone), 'exact');
        $this->assertTrue(LoginMatch::goesWithDeletedUser('anna', 'ANNA', '', $gone), 'deletion named in another spelling');
        $this->assertTrue(LoginMatch::goesWithDeletedUser('Anna', 'anna', '', $gone), 'a target typed in another case');
        $this->assertTrue(LoginMatch::goesWithDeletedUser('anna', 'ANNA', 'anna', self::users()), 'the stored spelling, while Matomo still knows the user');
        $this->assertFalse(LoginMatch::goesWithDeletedUser('Strasse', 'straße', '', $gone), 'a row of another existing user stays');
        $this->assertFalse(LoginMatch::goesWithDeletedUser('strasse', 'straße', '', $gone), 'also typed in another case');
    }

    public function test_rows_of_one_login(): void
    {
        $rows = [
            ['subscriber_login' => 'anna', 'iddashboard' => 3],
            ['subscriber_login' => 'Anna', 'iddashboard' => 4],
            ['iddashboard' => 5],
        ];
        $this->assertSame([['subscriber_login' => 'anna', 'iddashboard' => 3]], LoginMatch::rowsOf($rows, 'subscriber_login', 'anna'));
        $this->assertSame([], LoginMatch::rowsOf($rows, 'subscriber_login', 'ANNA'));
    }
}
