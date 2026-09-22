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

use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Service\Zone\ZoneMetadataOutcome;
use Poweradmin\Domain\Service\Zone\ZoneMetadataResult;

/**
 * Words a refused ZoneMetadataService write for the metadata editor.
 */
final class ZoneMetadataFormMessages
{
    public static function errorMessage(ZoneMetadataResult $result): string
    {
        $kind = $result->kind;

        return match ($result->outcome) {
            ZoneMetadataOutcome::INVALID_KIND => sprintf(_('Metadata kind %s is not valid.'), $kind),
            ZoneMetadataOutcome::EMPTY_VALUES => sprintf(_('Metadata kind %s needs a value.'), $kind),
            ZoneMetadataOutcome::SINGLE_VALUE_ONLY => sprintf(_('Metadata kind %s accepts only a single value. Add only one row for this kind.'), $kind),
            ZoneMetadataOutcome::INVALID_VALUE => sprintf(_('Invalid value for %s. Allowed values: %s.'), $kind, implode(', ', $result->options ?? [])),
            ZoneMetadataOutcome::COMPANION_REQUIRED => sprintf(
                _('Metadata kind %s only takes effect together with %s. Add a %s row as well.'),
                $kind,
                (string)$result->companion,
                (string)$result->companion
            ),
            ZoneMetadataOutcome::OPERATOR_ONLY => sprintf(_('Metadata kind %s can only be changed by an administrator.'), $kind),
            ZoneMetadataOutcome::SERVER_MANAGED => sprintf(_('Metadata kind %s is maintained by PowerDNS and cannot be changed here.'), $kind),
            ZoneMetadataOutcome::NO_API_ROUTE => sprintf(_('Metadata kind %s cannot be changed while the PowerDNS API backend is in use.'), $kind),
            ZoneMetadataOutcome::CUSTOM_PREFIX => sprintf(
                _('Custom metadata kind %s must start with %s to be accepted by the PowerDNS API.'),
                $kind,
                MetadataDefinitions::CUSTOM_KIND_API_PREFIX
            ),
            default => _('Failed to update zone metadata.'),
        };
    }
}
