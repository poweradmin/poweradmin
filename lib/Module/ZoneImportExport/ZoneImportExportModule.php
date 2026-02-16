<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Module\ZoneImportExport;

use Poweradmin\Module\ModuleInterface;

class ZoneImportExportModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'zone_import_export';
    }

    public function getDisplayName(): string
    {
        return 'Zone Import/Export';
    }

    public function getDescription(): string
    {
        return 'Import and export zones as BIND zone files';
    }

    public function getRoutes(): array
    {
        return [
            [
                'name' => 'module_zone_import',
                'path' => '/tools/zone-import',
                'controller' => 'Poweradmin\Module\ZoneImportExport\Controller\ZoneFileImportController::run',
                'methods' => ['GET', 'POST'],
            ],
            [
                'name' => 'module_zone_import_execute',
                'path' => '/tools/zone-import/execute',
                'controller' => 'Poweradmin\Module\ZoneImportExport\Controller\ZoneFileImportController::execute',
                'methods' => ['POST'],
            ],
            [
                'name' => 'module_zone_export',
                'path' => '/zones/{id}/export/zonefile',
                'controller' => 'Poweradmin\Module\ZoneImportExport\Controller\ZoneFileExportController::run',
                'methods' => ['GET'],
                'requirements' => ['id' => '\d+'],
            ],
        ];
    }

    public function getNavItems(): array
    {
        return [
            [
                'label' => 'Zone Import',
                'url' => '/tools/zone-import',
                'icon' => 'cloud-arrow-down',
                'page_id' => 'module_zone_import',
                'permission' => 'zone_master_add',
            ],
        ];
    }

    public function getCapabilities(): array
    {
        return ['zone_export', 'zone_import'];
    }

    public function getCapabilityData(string $capability): array
    {
        if ($capability === 'zone_export') {
            return [
                [
                    'label' => 'Zone File',
                    'url_pattern' => '/zones/{id}/export/zonefile',
                    'icon' => 'file-earmark-code',
                ],
            ];
        }
        return [];
    }

    public function getTemplatePath(): string
    {
        return __DIR__ . '/templates';
    }

    public function getLocalePath(): string
    {
        return __DIR__ . '/locale';
    }
}
