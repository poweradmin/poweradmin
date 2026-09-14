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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserManagementService;

/**
 * Words a refused UserManagementService write for the user forms. The
 * service's own message is the API wording; password policy, field length and
 * external-auth password messages are the same on both sides and pass through.
 */
class UserFormMessages
{
    /**
     * @param array{message?: string, code?: string} $result
     */
    public static function errorMessage(array $result): string
    {
        return match ($result['code'] ?? null) {
            UserManagementService::ERR_USERNAME_REQUIRED => _('Enter a valid user name.'),
            UserManagementService::ERR_USERNAME_EXISTS => _('Username exist already, please choose another one.'),
            UserManagementService::ERR_EMAIL_EXISTS => _('Email address already exists, please choose another one.'),
            UserManagementService::ERR_PASSWORD_REQUIRED => _('Please fill in all required fields correctly.'),
            UserManagementService::ERR_NO_TEMPLATE => _('No non-superuser permission template is available to assign.'),
            UserManagementService::ERR_TEMPLATE_NOT_FOUND => _('Invalid permission template: must be a user template'),
            UserManagementService::ERR_INVALID_LDAP => _('Invalid or unexpected input given.'),
            UserManagementService::ERR_NOT_FOUND => _('User does not exist.'),
            UserManagementService::ERR_LAST_ADMIN => _('Cannot disable the last remaining super admin user.'),
            // The write message carries the driver's text, which is for the log, not the page.
            UserManagementService::ERR_WRITE => _('The user could not be saved.'),
            default => (string)($result['message'] ?? ''),
        };
    }

    /**
     * Words a refused deletion; the same codes as errorMessage(), read for that page.
     *
     * @param array{message?: string, code?: string} $result
     */
    public static function deleteErrorMessage(array $result): string
    {
        return match ($result['code'] ?? null) {
            UserManagementService::ERR_LAST_ADMIN => _('Cannot delete the last remaining super admin user.'),
            UserManagementService::ERR_ZONE_DELETE_FORBIDDEN => _('You do not have the permission to delete a zone.'),
            UserManagementService::ERR_ZONE_META_FORBIDDEN => _('You do not have the permission to edit zone metadata.'),
            UserManagementService::ERR_WRITE => _('The user could not be deleted.'),
            // ERR_ZONE_WRITE carries DomainManager's translated reason and falls through
            default => self::errorMessage($result),
        };
    }

    /**
     * Words a refused permission template assignment from PermissionTemplateAssignmentGuard.
     */
    public static function templateAssignmentError(string $error): string
    {
        return match ($error) {
            PermissionService::TEMPLATE_SELF_ASSIGN_DENIED => _('Changing your own permission template requires the permission to edit other users.'),
            PermissionService::TEMPLATE_SUPERUSER_DENIED => _('Assigning a permission template with administrator rights requires administrator rights.'),
            default => _('You do not have the permission to change the permission template.'),
        };
    }
}
