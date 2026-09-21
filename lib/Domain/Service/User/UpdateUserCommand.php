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

namespace Poweradmin\Domain\Service\User;

/**
 * What a partial user update asks for: every field is null when the request
 * left it alone. The controllers build it from their own field names; the
 * service hands it on to the repository with the password in its stored form.
 */
final readonly class UpdateUserCommand
{
    /**
     * @param string|null $username A new login name; an empty one is refused by the service
     * @param string|null $password A new plain password; null (and an empty string) leaves it unchanged
     * @param int|null $permissionTemplateId A positive template id to assign
     * @param bool|null $useLdap Whether the account should authenticate against LDAP
     */
    public function __construct(
        public ?string $username = null,
        #[\SensitiveParameter] public ?string $password = null,
        public ?string $fullname = null,
        public ?string $email = null,
        public ?string $description = null,
        public ?bool $active = null,
        public ?int $permissionTemplateId = null,
        public ?bool $useLdap = null,
    ) {
    }

    /** Whether the request sets a password; "0" is a password, an empty string is not. */
    public function passwordGiven(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    /** The same request with the password replaced by its stored form (hash or LDAP placeholder). */
    public function withPassword(#[\SensitiveParameter] string $password): self
    {
        return new self(
            $this->username,
            $password,
            $this->fullname,
            $this->email,
            $this->description,
            $this->active,
            $this->permissionTemplateId,
            $this->useLdap,
        );
    }
}
