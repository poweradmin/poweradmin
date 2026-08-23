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
 * Lifecycle state of an API key.
 *
 * Derived from the existing `disabled` and `expires_at` columns, so there is no
 * schema change. Disabled beats expired, matching what the key list has always
 * shown when both are true.
 *
 * Read-only is deliberately not a case here: it narrows what a key may do, not
 * whether it works at all.
 */
enum ApiKeyStatus: string
{
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
    case EXPIRED = 'expired';

    /**
     * Whether a key in this state may still authenticate.
     */
    public function isUsable(): bool
    {
        return $this === self::ACTIVE;
    }
}
