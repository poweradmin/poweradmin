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

namespace Poweradmin\Module\EmailPreviews;

use Poweradmin\Module\ModuleInterface;

/**
 * Module registration for the /tools/email-previews page and its tools menu entry.
 */
class EmailPreviewsModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'email_previews';
    }

    public function getDisplayName(): string
    {
        return 'Email Template Previews';
    }

    public function getDescription(): string
    {
        return 'Preview email templates in light and dark modes';
    }

    public function getRoutes(): array
    {
        return [
            [
                'name' => 'module_email_previews',
                'path' => '/tools/email-previews',
                'controller' => 'Poweradmin\Module\EmailPreviews\Controller\EmailPreviewsController::run',
                'methods' => ['GET'],
            ],
        ];
    }

    public function getNavItems(): array
    {
        return [
            [
                'label' => 'Email Template Previews',
                'url' => '/tools/email-previews',
                'icon' => 'envelope-fill',
                'page_id' => 'module_email_previews',
                'permission' => 'user_is_ueberuser',
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
        return '';
    }

    public function getLocalePath(): string
    {
        return '';
    }
}
