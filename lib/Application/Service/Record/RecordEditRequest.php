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

namespace Poweradmin\Application\Service\Record;

/**
 * One record edit as the edit-record form or an API PUT submits it. The caller
 * has already found and authorised the stored row; the service normalises the
 * submitted values and writes.
 */
final readonly class RecordEditRequest
{
    /**
     * @param int|string $recordId Numeric id, or the encoded identifier of an API-backed record
     * @param array<string, mixed> $current The stored row being edited (name, type, content, ttl, prio)
     * @param string|null $comment The record comment to store, or null to leave comments as they are
     *                             (a rename then only carries an existing comment along)
     * @param bool $syncPtr Move the PTR of an A/AAAA record along with the edit
     * @param bool $bumpSoaSerial The web form lets an SOA carry a serial placeholder whose expanded
     *                            value is bumped after the write (#1360); API callers keep the serial
     *                            they sent and pass false
     */
    public function __construct(
        public int $zoneId,
        public string $zoneName,
        public int|string $recordId,
        public array $current,
        public string $name,
        public string $type,
        public string $content,
        public int $ttl,
        public int $prio,
        public int $disabled,
        public ?string $comment,
        public bool $syncPtr,
        public bool $bumpSoaSerial,
        public string $username
    ) {
    }
}
