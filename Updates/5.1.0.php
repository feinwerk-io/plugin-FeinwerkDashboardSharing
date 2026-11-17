<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

use Piwik\Plugins\FeinwerkDashboardSharing\Dao\Sharing;
use Piwik\Updater;
use Piwik\Updater\Migration\Factory as MigrationFactory;
use Piwik\Updates as PiwikUpdates;

/**
 * Gives the login columns of the three tables (share owner, share target, subscriber) the binary collation of their
 * character set, so two Matomo users whose logins the old collation compared as equal ("Anna" and "anna") get separate
 * keys. No row changes: targets keep the case the owner typed, the lookups find them in every case. Checks each column
 * first: nothing to do (and no migration shown) when it already compares exactly or its table does not exist. Not a log
 * table, so no major update. The main line (Matomo 6) has the same change as Updates/6.0.0-beta.4.php.
 */
class Updates_5_1_0 extends PiwikUpdates
{
    /** @var MigrationFactory */
    private $migration;

    public function __construct(MigrationFactory $factory)
    {
        $this->migration = $factory;
    }

    public function getMigrations(Updater $updater)
    {
        $dao = new Sharing();
        $migrations = array();
        foreach (Sharing::loginColumns() as $table => $column) {
            $type = $dao->exactLoginColumnType($table, $column);
            if ($type !== null) {
                $migrations[] = $this->migration->db->changeColumnType($table, $column, $type);
            }
        }

        return $migrations;
    }

    public function doUpdate(Updater $updater)
    {
        $updater->executeMigrations(__FILE__, $this->getMigrations($updater));
    }
}
