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

namespace Poweradmin\Infrastructure\Logger;

/**
 * Makes values safe for the "name:value" audit lines, which fail2ban filters and the log
 * exporters read for client_ip, user and operation. A value must not break the line or pass
 * for another field.
 */
final class AuditLineValue
{
    /**
     * No control characters, and no "word:" that could pass for a field. Colons after digits
     * (IPv6 addresses, times) stay readable.
     */
    public static function of(?string $value): string
    {
        $value = (string)preg_replace('/[\x00-\x1F\x7F]/', '?', (string)$value);
        $encoded = preg_replace('/(?<=[\p{L}_]):/u', '%3A', $value);

        return $encoded ?? (string)preg_replace('/(?<=[A-Za-z_]):/', '%3A', $value);
    }
}
