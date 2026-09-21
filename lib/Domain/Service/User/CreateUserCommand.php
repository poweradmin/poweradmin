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
 * What a create-user request asks for, typed and independent of the wire
 * format. The controllers build it from their own field names; the service
 * hands it on to the repository with the password in its stored form.
 */
final readonly class CreateUserCommand
{
    /**
     * @param string $username The login name; empty is refused by the service
     * @param string|null $password The plain password, or null when none was given
     * @param int|null $permissionTemplateId A positive template id, or null when none was resolved
     * @param bool $useLdap Whether the account authenticates against LDAP
     */
    public function __construct(
        public string $username,
        #[\SensitiveParameter] public ?string $password,
        public string $fullname = '',
        public string $email = '',
        public string $description = '',
        public bool $active = true,
        public ?int $permissionTemplateId = null,
        public bool $useLdap = false,
    ) {
    }

    /** Whether the request carries a password; "0" is a password, an empty string is not. */
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
