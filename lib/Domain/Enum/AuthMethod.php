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


namespace Poweradmin\Domain\Enum;

/**
 * How a user authenticates, as stored in `users.auth_method`.
 *
 * This is the single source of truth for the vocabulary. Callers that still
 * need the raw string use `->value`; callers that need to classify a method
 * should use the predicates here rather than re-listing cases.
 */
enum AuthMethod: string
{
    case SQL = 'sql';
    case LDAP = 'ldap';
    case OIDC = 'oidc';
    case SAML = 'saml';

    /**
     * Read a stored or session value, falling back to SQL for anything
     * unrecognised (including null, which pre-dates the column).
     */
    public static function fromDb(?string $value, self $default = self::SQL): self
    {
        return $value === null ? $default : (self::tryFrom($value) ?? $default);
    }

    /**
     * Whether a string names a known method.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }

    /**
     * Authentication happens outside Poweradmin, so there is no local password
     * to change and MFA may already be enforced by the provider.
     */
    public function isExternal(): bool
    {
        return $this !== self::SQL;
    }

    /**
     * The inverse of isExternal(): only SQL accounts own a local password.
     */
    public function allowsLocalPassword(): bool
    {
        return $this === self::SQL;
    }

    /**
     * Whether the identity provider owns fullname and email, making them
     * read-only locally.
     *
     * OIDC and SAML re-sync on every login. LDAP only does so while
     * `ldap.sync_user_info` is on, which the caller passes in.
     */
    public function isIdpManaged(bool $ldapSynced = false): bool
    {
        return match ($this) {
            self::OIDC, self::SAML => true,
            self::LDAP => $ldapSynced,
            self::SQL => false,
        };
    }

    /**
     * The method to persist when the legacy `use_ldap` flag is written.
     *
     * Turning LDAP off must not silently downgrade an SSO account to SQL, so an
     * existing OIDC or SAML method is preserved.
     */
    public static function resolve(bool $useLdap, ?string $currentAuthMethod): self
    {
        if ($useLdap) {
            return self::LDAP;
        }

        $current = $currentAuthMethod === null ? null : self::tryFrom($currentAuthMethod);

        return $current !== null && $current->isIdpManaged() ? $current : self::SQL;
    }
}
