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

namespace Poweradmin\Domain\Service\Zone;

/**
 * Why a ZoneMetadataService write was refused, or OK. Callers word these for
 * their own audience; the rules are the same for the editor and the API.
 */
enum ZoneMetadataOutcome: string
{
    case OK = 'ok';
    case INVALID_KIND = 'invalid_kind';
    case EMPTY_VALUES = 'empty_values';
    case SINGLE_VALUE_ONLY = 'single_value_only';
    case INVALID_VALUE = 'invalid_value';
    case COMPANION_REQUIRED = 'companion_required';
    case OPERATOR_ONLY = 'operator_only';
    case SERVER_MANAGED = 'server_managed';
    case NO_API_ROUTE = 'no_api_route';
    case CUSTOM_PREFIX = 'custom_prefix';
    case WRITE_FAILED = 'write_failed';
}
