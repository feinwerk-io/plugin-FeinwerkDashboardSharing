<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Integration;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;

/**
 * The plugin's tables as an install before the update to the binary login collation may have them: Matomo 5.0 created
 * them with the server's default collation, which can treat "Anna" and "anna" as the same login. install() gives the
 * login columns the binary collation, so tests of that older state convert the tables back, whatever the test server's
 * default is. The skip remains only for a database that cannot do that.
 */
trait TablesBeforeTheUpdate
{
    private function giveTheTablesACaseInsensitiveCollation(): void
    {
        foreach (array_keys(Sharing::loginColumns()) as $table) {
            try {
                Db::query(sprintf(
                    'ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
                    Common::prefixTable($table)
                ));
            } catch (\Exception $e) {
                $this->markTestSkipped('the test tables cannot be given a case-insensitive collation: ' . $e->getMessage());
            }
        }

        $sharing = new Sharing();
        $probe = $sharing->createShare('Anna', 1, 'probe', true, array(), '2026-06-16 10:00:00');
        $same = (int) Db::fetchOne(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `owner_login` = ?', Common::prefixTable('shared_dashboard')),
            array('anna')
        );
        $sharing->deleteShare($probe);
        if ($same === 0) {
            $this->markTestSkipped('the test database compares logins case-sensitively: no conflation to guard against');
        }
    }
}
