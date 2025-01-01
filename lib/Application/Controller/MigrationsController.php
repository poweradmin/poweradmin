<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Application\Controller;

use Exception;
use Phinx\Console\PhinxApplication;
use Poweradmin\BaseController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationsController extends BaseController
{
    const PHINX_CONFIG_PATH = __DIR__ . '/../../../phinx.php';

    public function run(): void
    {
        $this->checkPermission('user_is_ueberuser', _('You do not have the permission to access the migrations.'));

        $db_type = $this->config('db_type');
        if ($this->checkOldMigrationsTable($db_type)) {
            $this->render('migrations.html', [
                'output' => _('Old migrations table detected. Please remove the `migrations` table before proceeding.'),
            ]);
            return;
        }

        if (!$this->checkDatabaseAccess($db_type)) {
            $this->render('migrations.html', [
                'output' => _('Database access check failed. Please ensure the current database user has the necessary permissions to create and alter tables.'),            ]);
            return;
        }

        try {
            $app = new PhinxApplication();
            $app->setAutoExit(false);

            $input = new ArrayInput([
                'command' => 'migrate',
                '--configuration' => self::PHINX_CONFIG_PATH,
            ]);

            $output = new BufferedOutput();
            $app->run($input, $output);

            $migrationOutput = htmlspecialchars($output->fetch(), ENT_QUOTES, 'UTF-8');
            $output = $migrationOutput;
        } catch (Exception $e) {
            $output = $e->getMessage();
        }

        $this->render('migrations.html', [
            'output' => $output,
        ]);
    }

    private function checkOldMigrationsTable(string $db_type): bool
    {
        switch ($db_type) {
            case 'mysql':
                $query = $this->db->query("SHOW COLUMNS FROM `migrations` LIKE 'apply_time'");
                break;
            case 'pgsql':
                $query = $this->db->query("SELECT column_name FROM information_schema.columns WHERE table_name='migrations' AND column_name='apply_time'");
                break;
            case 'sqlite':
                $query = $this->db->query("PRAGMA table_info(migrations)");
                $columns = $query->fetchAll();
                foreach ($columns as $column) {
                    if ($column['name'] === 'apply_time') {
                        return true;
                    }
                }
                return false;
            default:
                return false;
        }

        $result = $query->fetch();
        return (bool)$result;
    }

    private function checkDatabaseAccess(string $db_type): bool
    {
        if ($db_type === 'sqlite') {
            return true;
        }

        switch ($db_type) {
            case 'mysql':
                $query = $this->db->query("SHOW GRANTS FOR CURRENT_USER");
                $result = $query->fetchAll();
                break;
            case 'pgsql':
                $query = $this->db->query("SELECT has_schema_privilege(current_user, 'public', 'CREATE') AS can_create");
                $result = $query->fetch();
                break;
            default:
                return false;
        }

        return false;
    }
}