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
 * What a ZoneSigningService sign or unsign request came to. Callers word
 * these for their own audience; the API and the web pages agree on the steps.
 */
enum ZoneSigningOutcome: string
{
    case SIGNED = 'signed';
    case UNSIGNED = 'unsigned';
    case SERVER_DISABLED = 'server_disabled';
    case PRESIGNED = 'presigned';
    case ALREADY_SIGNED = 'already_signed';
    case NOT_SIGNED = 'not_signed';
    case INVALID_ZONE = 'invalid_zone';
    case SECURE_FAILED = 'secure_failed';
    case UNSECURE_FAILED = 'unsecure_failed';
    case VERIFY_FAILED = 'verify_failed';
}
