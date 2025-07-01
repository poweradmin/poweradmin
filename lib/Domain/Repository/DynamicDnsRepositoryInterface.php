<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\ValueObject\HostnameValue;

interface DynamicDnsRepositoryInterface
{
    public function findUserByUsernameWithDynamicDnsPermissions(string $username): ?User;

    public function getUserZones(User $user): array;

    public function getDnsRecords(int $zoneId, HostnameValue $hostname, string $recordType): array;

    public function insertDnsRecord(int $zoneId, HostnameValue $hostname, string $recordType, string $content): void;

    public function deleteDnsRecord(int $recordId): void;

    public function updateSOASerial(int $zoneId): void;
}
