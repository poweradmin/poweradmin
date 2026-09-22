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

use Poweradmin\Domain\Service\Zone\ZoneManagementService;

/**
 * Words a refused ZoneManagementService::createZone() for the add-zone forms.
 * The service's own message is the API wording; a zone write refusal already
 * carries the translated reason from DomainManager.
 */
final class ZoneCreateFormMessages
{
    /**
     * The reverse-zone form got neither a network nor a reverse zone name.
     */
    public static function invalidReverseNetwork(): string
    {
        return _('Enter a network in CIDR notation (for example 192.168.1.0/24 or 2001:db8::/48) or a reverse zone name ending in in-addr.arpa or ip6.arpa.');
    }

    public static function dnssecForbidden(): string
    {
        return _('You do not have permission to manage DNSSEC for this zone.');
    }

    /**
     * @param array{message?: string, code?: string} $result
     */
    public static function errorMessage(array $result): string
    {
        return match ($result['code'] ?? null) {
            ZoneManagementService::ERR_NO_OWNER => _('At least one user or group must be selected as owner.'),
            ZoneManagementService::ERR_INVALID_NAME => _('Invalid hostname.'),
            ZoneManagementService::ERR_EXISTS => _('There is already a zone with this name.'),
            ZoneManagementService::ERR_OVERLAP => _('Cannot create this zone because it overlaps an existing zone owned by another user.'),
            ZoneManagementService::ERR_MASTER_REQUIRED,
            ZoneManagementService::ERR_INVALID_MASTER => _('This is not a valid IPv4 or IPv6 address.'),
            ZoneManagementService::ERR_INVALID_TYPE,
            ZoneManagementService::ERR_INVALID_SOA_EDIT_API,
            ZoneManagementService::ERR_TEMPLATE_NOT_FOUND,
            ZoneManagementService::ERR_TEMPLATE_AMBIGUOUS,
            ZoneManagementService::ERR_TEMPLATE_FORBIDDEN => _('Invalid or unexpected input given.'),
            default => (string)($result['message'] ?? ''),
        };
    }
}
