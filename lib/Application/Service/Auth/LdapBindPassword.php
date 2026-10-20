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

namespace Poweradmin\Application\Service\Auth;

/**
 * Decides whether a user-supplied password may be sent to an LDAP simple bind.
 *
 * A zero-length password turns the bind into an unauthenticated bind, which Active
 * Directory and other servers report as a success. A password containing a NUL byte
 * makes ldap_bind() throw a TypeError, which surfaces as a server error and skips
 * the failed-attempt bookkeeping.
 */
final class LdapBindPassword
{
    public static function isUsable(#[\SensitiveParameter] string $password): bool
    {
        return $password !== '' && !str_contains($password, "\0");
    }
}
