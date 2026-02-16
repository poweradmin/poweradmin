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

namespace Poweradmin\Module\CsvExport;

use Poweradmin\Module\ModuleInterface;

class CsvExportModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'csv_export';
    }

    public function getDisplayName(): string
    {
        return 'CSV Export';
    }

    public function getDescription(): string
    {
        return 'Export zone records as CSV files';
    }

    public function getRoutes(): array
    {
        return [
            [
                'name' => 'module_csv_export',
                'path' => '/zones/{id}/export/csv',
                'controller' => 'Poweradmin\Module\CsvExport\Controller\CsvExportController::run',
                'methods' => ['GET'],
                'requirements' => ['id' => '\d+'],
            ],
        ];
    }

    public function getNavItems(): array
    {
        return [];
    }

    public function getCapabilities(): array
    {
        return ['zone_export'];
    }

    public function getCapabilityData(string $capability): array
    {
        if ($capability === 'zone_export') {
            return [
                [
                    'label' => 'CSV',
                    'url_pattern' => '/zones/{id}/export/csv',
                    'icon' => 'file-earmark-spreadsheet',
                ],
            ];
        }
        return [];
    }

    public function getTemplatePath(): string
    {
        return '';
    }

    public function getLocalePath(): string
    {
        return __DIR__ . '/locale';
    }
}
