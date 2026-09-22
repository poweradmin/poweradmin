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

namespace Poweradmin\Application\Service\Zone;

/**
 * What a zone creation form submitted, as ZoneCreateService takes it: the
 * name as typed, the owner controls as posted, and the kind-specific options.
 */
final readonly class ZoneCreateRequest
{
    /**
     * @param string $name The zone name as typed; on the reverse form a network in CIDR notation is accepted too
     * @param string $type MASTER, NATIVE, SLAVE, PRODUCER or CONSUMER
     * @param mixed $ownerInput The posted owner field, if any
     * @param mixed $groupsInput The posted groups field, if any
     * @param string $slaveMaster The primary address for a replicating zone, '' otherwise
     * @param string $template The zone template id, or 'none'
     * @param bool $signRequested Whether to sign the zone with DNSSEC after creating it
     * @param string|null $soaEditApi Per-zone SOA-EDIT-API choice; null applies the configured default
     * @param bool $reverseNetwork Whether the name came from the reverse-zone form
     * @param bool $importedFromPrimary Whether the zone is imported from a live primary (audited as an import)
     */
    public function __construct(
        public string $name,
        public string $type,
        public mixed $ownerInput,
        public mixed $groupsInput,
        public int $callerUserId,
        public string $slaveMaster = '',
        public string $template = 'none',
        public bool $signRequested = false,
        public ?string $soaEditApi = null,
        public bool $reverseNetwork = false,
        public bool $importedFromPrimary = false
    ) {
    }
}
