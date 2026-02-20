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

namespace Poweradmin\Module\Whois;

use Poweradmin\Module\ModuleInterface;

class WhoisModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'whois';
    }

    public function getDisplayName(): string
    {
        return 'WHOIS Lookup';
    }

    public function getDescription(): string
    {
        return 'Lookup domain WHOIS information';
    }

    public function getRoutes(): array
    {
        return [
            [
                'name' => 'module_whois',
                'path' => '/whois',
                'controller' => 'Poweradmin\Module\Whois\Controller\WhoisController::run',
                'methods' => ['GET', 'POST'],
            ],
            [
                'name' => 'module_zone_whois',
                'path' => '/zones/{id}/whois',
                'controller' => 'Poweradmin\Module\Whois\Controller\WhoisController::run',
                'methods' => ['GET'],
                'requirements' => ['id' => '\d+'],
            ],
        ];
    }

    public function getNavItems(): array
    {
        return [
            [
                'label' => 'WHOIS',
                'url' => '/whois',
                'icon' => 'search-heart',
                'page_id' => 'module_whois',
                'permission' => '',
            ],
        ];
    }

    public function getCapabilities(): array
    {
        return ['whois_lookup'];
    }

    public function getCapabilityData(string $capability): array
    {
        if ($capability === 'whois_lookup') {
            return [
                [
                    'label' => 'WHOIS',
                    'url_pattern' => '/zones/{id}/whois',
                    'icon' => 'search-heart',
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
        return '';
    }
}
