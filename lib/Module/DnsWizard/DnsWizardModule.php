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

namespace Poweradmin\Module\DnsWizard;

use Poweradmin\Module\ModuleInterface;

class DnsWizardModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'dns_wizards';
    }

    public function getDisplayName(): string
    {
        return 'DNS Record Wizards';
    }

    public function getDescription(): string
    {
        return 'Guided wizards for creating DMARC, SPF, DKIM, CAA, TLSA, and SRV records';
    }

    public function getRoutes(): array
    {
        return [
            [
                'name' => 'module_dns_wizard_select',
                'path' => '/zones/{id}/wizard',
                'controller' => 'Poweradmin\Module\DnsWizard\Controller\DnsWizardSelectController::run',
                'methods' => ['GET'],
                'requirements' => ['id' => '\d+'],
            ],
            [
                'name' => 'module_dns_wizard_form',
                'path' => '/zones/{id}/wizard/{type}',
                'controller' => 'Poweradmin\Module\DnsWizard\Controller\DnsWizardFormController::run',
                'methods' => ['GET', 'POST'],
                'requirements' => ['id' => '\d+', 'type' => '[a-zA-Z]+'],
            ],
            [
                'name' => 'module_api_internal_dns_wizard',
                'path' => '/api/internal/dns-wizard',
                'controller' => 'Poweradmin\Module\DnsWizard\Controller\Api\DnsWizardApiController::run',
                'methods' => ['GET', 'POST'],
            ],
        ];
    }

    public function getNavItems(): array
    {
        return [];
    }

    public function getCapabilities(): array
    {
        return ['dns_wizard'];
    }

    public function getCapabilityData(string $capability): array
    {
        if ($capability === 'dns_wizard') {
            return [
                [
                    'label' => _('Record Wizard'),
                    'url_pattern' => '/zones/{id}/wizard',
                    'icon' => 'magic',
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
        return '';
    }
}
