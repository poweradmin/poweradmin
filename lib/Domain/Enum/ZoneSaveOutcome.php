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
 * How a zone-edit save ended.
 *
 * This replaces a trio of co-varying booleans whose illegal combinations
 * (a failed write on an unchanged record, a serial conflict that also reported
 * changes) were representable but meaningless.
 *
 * Unbacked: this is in-process control flow, never persisted or serialised.
 */
enum ZoneSaveOutcome
{
    /** Records changed and the SOA serial was bumped. */
    case UPDATED;

    /** Nothing changed, but the SOA serial was still bumped. */
    case NO_CHANGES;

    /** At least one record failed to write. */
    case WRITE_FAILED;

    /** The form was stale and misc.edit_conflict_resolution refuses the write. */
    case SERIAL_CONFLICT;

    /**
     * Whether the zone was actually written, and so needs a serial bump and
     * a DNSSEC rectify.
     */
    public function wasWritten(): bool
    {
        return $this === self::UPDATED || $this === self::NO_CHANGES;
    }
}
