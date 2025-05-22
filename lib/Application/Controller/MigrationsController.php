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
use Poweradmin\Domain\Service\DatabaseSchemaService;
use Poweradmin\Domain\Service\DatabasePermissionService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationsController extends BaseController
{
    private const PHINX_CONFIG_PATH = __DIR__ . '/../../../tools/phinx.php';

    private DatabaseSchemaService $schemaService;
    private DatabasePermissionService $permissionService;

    public function __construct(array $request, bool $authenticate = true)
    {
        parent::__construct($request, $authenticate);
        $this->schemaService = new DatabaseSchemaService($this->db);
        $this->permissionService = new DatabasePermissionService($this->db);
    }

    public function run(): void
    {
        $this->checkPermission('user_is_ueberuser', _('You do not have the permission to access the migrations.'));

        if ($this->schemaService->hasOldMigrationsTable()) {
            $this->render('migrations.html', [
                'output' => _('Old migrations table detected. Please remove the `migrations` table before proceeding.'),
            ]);
            return;
        }

        if (!$this->permissionService->hasCreateAndAlterPermissions()) {
            $this->render('migrations.html', [
                'output' => _('Database access check failed. Please ensure the current database user has the necessary permissions to create and alter tables.'),
            ]);
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
}
