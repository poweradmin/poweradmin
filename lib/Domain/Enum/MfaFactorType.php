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
 * Second factor a user verifies with.
 *
 * Values match the `user_mfa.type` column, so no data migration is involved.
 */
enum MfaFactorType: string
{
    case APP = 'app';
    case EMAIL = 'email';

    /**
     * Whether a string names a known factor type.
     */
    public static function isValid(string $type): bool
    {
        return self::tryFrom($type) !== null;
    }

    /**
     * The config key under `security.mfa` that enables this factor.
     */
    public function configKey(): string
    {
        return match ($this) {
            self::APP => 'mfa.app_enabled',
            self::EMAIL => 'mfa.email_enabled',
        };
    }
}
