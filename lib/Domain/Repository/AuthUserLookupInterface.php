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

namespace Poweradmin\Domain\Repository;

/**
 * The account rows the authenticators read while deciding a login.
 *
 * Each method carries the exact column set and the active/use_ldap filter its
 * authenticator relies on, so the filter cannot be dropped at a call site.
 */
interface AuthUserLookupInterface
{
    /**
     * The password-login row: id, fullname, password, active, email. LDAP accounts
     * are excluded so a directory account cannot be logged into with a local hash.
     */
    public function findSqlLoginUser(string $username): ?array;

    /**
     * The LDAP-login row: id, fullname, email, for active LDAP-enabled accounts only.
     */
    public function findActiveLdapUser(string $username): ?array;

    /**
     * Whether the account is still active and still LDAP-enabled, re-checked on
     * every request so a disabled account loses a cached LDAP bind at once.
     */
    public function hasActiveLdapUser(string $username): bool;

    /**
     * The HTTP Basic row: id, password, use_ldap, for active accounts only.
     */
    public function findBasicAuthUser(string $username): ?array;

    /**
     * The stored auth_method for a username, or null when no such account exists.
     * The row is returned rather than the value so a missing account stays
     * distinguishable from an account with no auth_method set.
     *
     * @return array{auth_method: string|null}|null
     */
    public function findAuthMethodRow(string $username): ?array;
}
