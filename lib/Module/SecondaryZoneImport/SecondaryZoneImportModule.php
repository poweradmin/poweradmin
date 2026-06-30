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

namespace Poweradmin\Module\SecondaryZoneImport;

use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Module\ModuleInterface;

/**
 * Imports a zone from a live primary server by creating a secondary zone,
 * pulling it over AXFR, and converting it to a primary once the data lands.
 *
 * This relies on PowerDNS performing the transfer, so it is only available
 * with the API backend; it hides itself entirely in SQL-backend mode.
 */
class SecondaryZoneImportModule implements ModuleInterface
{
    private ?bool $apiBackendMode = null;

    public function getName(): string
    {
        return 'secondary_zone_import';
    }

    public function getDisplayName(): string
    {
        return 'Import secondary zone';
    }

    public function getDescription(): string
    {
        return 'Import a zone from a live primary server over AXFR (API backend only)';
    }

    public function getRoutes(): array
    {
        if (!$this->isApiBackendMode()) {
            return [];
        }

        return [
            [
                'name' => 'module_secondary_zone_import',
                'path' => '/zones/import-secondary',
                'controller' => 'Poweradmin\Module\SecondaryZoneImport\Controller\SecondaryZoneImportController::run',
                'methods' => ['GET', 'POST'],
            ],
        ];
    }

    public function getNavItems(): array
    {
        if (!$this->isApiBackendMode()) {
            return [];
        }

        return [
            [
                'label' => 'Import secondary zone',
                'url' => '/zones/import-secondary',
                'icon' => 'cloud-arrow-down',
                'page_id' => 'module_secondary_zone_import',
                'permission' => 'zone_slave_add',
            ],
        ];
    }

    public function getCapabilities(): array
    {
        return [];
    }

    public function getCapabilityData(string $capability): array
    {
        return [];
    }

    public function getTemplatePath(): string
    {
        return __DIR__ . '/templates';
    }

    public function getLocalePath(): string
    {
        return '';
    }

    private function isApiBackendMode(): bool
    {
        if ($this->apiBackendMode === null) {
            $this->apiBackendMode = DnsBackendProviderFactory::isApiBackend(ConfigurationManager::getInstance());
        }
        return $this->apiBackendMode;
    }
}
