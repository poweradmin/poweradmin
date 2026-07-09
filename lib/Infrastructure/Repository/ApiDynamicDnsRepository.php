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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\DynamicDnsRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\ValueObject\HostnameValue;

/**
 * API-backend dynamic DNS repository.
 * Uses PowerDNS REST API for DNS operations, Poweradmin DB for user/zone queries.
 */
class ApiDynamicDnsRepository implements DynamicDnsRepositoryInterface
{
    public function __construct(
        private readonly PDO $db,
        private readonly SOARecordManagerInterface $soaRecordManager,
        private readonly DnsBackendProvider $backendProvider
    ) {
    }

    public function findUserByUsernameWithDynamicDnsPermissions(string $username): ?User
    {
        // DDNS auth requires an explicit zone_content_edit_* grant from either the
        // user's direct template or any group they belong to (matching the web UI);
        // ueberuser bypass is deliberately omitted so admin credentials never have
        // to live in ddclient.conf.
        $query = $this->db->prepare("
            SELECT users.id, users.password, users.use_ldap
            FROM users
            WHERE users.username = :username
                AND users.active = 1
                AND (
                    EXISTS (
                        SELECT 1 FROM perm_templ_items pti
                        JOIN perm_items pi ON pi.id = pti.perm_id
                        WHERE pti.templ_id = users.perm_templ
                            AND pi.name IN ('zone_content_edit_own', 'zone_content_edit_own_as_client', 'zone_content_edit_others')
                    )
                    OR EXISTS (
                        SELECT 1 FROM user_group_members ugm
                        JOIN user_groups ug ON ug.id = ugm.group_id
                        JOIN perm_templ_items pti ON pti.templ_id = ug.perm_templ
                        JOIN perm_items pi ON pi.id = pti.perm_id
                        WHERE ugm.user_id = users.id
                            AND pi.name IN ('zone_content_edit_own', 'zone_content_edit_own_as_client', 'zone_content_edit_others')
                    )
                )
        ");

        $query->execute([':username' => $username]);
        $userData = $query->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            return null;
        }

        return new User(
            (int)$userData['id'],
            $userData['password'],
            (bool)$userData['use_ldap']
        );
    }

    public function getUserZones(User $user): array
    {
        // SQL stays inside Poweradmin-native tables; zone names come from the API.
        // Edit permission is source-specific (matching the web permission model): a zone is
        // only returned when the owner's template (the user's for direct ownership, or the
        // owning group's) grants zone_content_edit_*.
        $query = $this->db->prepare("
            SELECT DISTINCT z.domain_id
            FROM zones z
            INNER JOIN users u ON u.id = z.owner
            WHERE z.owner = :user_id
                AND EXISTS (
                    SELECT 1 FROM perm_templ_items pti
                    JOIN perm_items pi ON pi.id = pti.perm_id
                    WHERE pti.templ_id = u.perm_templ
                        AND pi.name IN ('zone_content_edit_own', 'zone_content_edit_own_as_client', 'zone_content_edit_others')
                )

            UNION

            SELECT DISTINCT zg.domain_id
            FROM zones_groups zg
            INNER JOIN user_group_members ugm ON ugm.group_id = zg.group_id
            INNER JOIN user_groups ug ON ug.id = zg.group_id
            WHERE ugm.user_id = :user_id2
                AND EXISTS (
                    SELECT 1 FROM perm_templ_items pti
                    JOIN perm_items pi ON pi.id = pti.perm_id
                    WHERE pti.templ_id = ug.perm_templ
                        AND pi.name IN ('zone_content_edit_own', 'zone_content_edit_own_as_client', 'zone_content_edit_others')
                )
        ");
        $query->execute([
            ':user_id' => $user->getId(),
            ':user_id2' => $user->getId(),
        ]);

        $zones = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $zoneId = (int)$row['domain_id'];
            $name = $this->backendProvider->getZoneNameById($zoneId);
            if ($name !== null) {
                $zones[$zoneId] = $name;
            }
        }

        return $zones;
    }

    public function getZoneType(int $zoneId): ?string
    {
        // Read zone kind via the API; this repository never queries PowerDNS tables
        return $this->backendProvider->getZoneTypeById($zoneId) ?: null;
    }

    public function getDnsRecords(int $zoneId, HostnameValue $hostname, string $recordType): array
    {
        $allRecords = $this->backendProvider->getRecordsByZoneId($zoneId, $recordType);
        $records = [];
        foreach ($allRecords as $r) {
            if (($r['name'] ?? '') === $hostname->getValue()) {
                $records[$r['content'] ?? ''] = $r['id'] ?? 0;
            }
        }
        return $records;
    }

    public function insertDnsRecord(int $zoneId, HostnameValue $hostname, string $recordType, string $content): void
    {
        $result = $this->backendProvider->addRecord($zoneId, $hostname->getValue(), $recordType, $content, 60, 0);
        if (!$result) {
            throw new \RuntimeException("Failed to add DNS record via API for zone $zoneId");
        }
    }

    public function deleteDnsRecord(int|string $recordId): void
    {
        $result = $this->backendProvider->deleteRecord($recordId);
        if (!$result) {
            throw new \RuntimeException("Failed to delete DNS record via API for record $recordId");
        }
    }

    public function updateSOASerial(int $zoneId): void
    {
        $this->soaRecordManager->updateSOASerial($zoneId);
    }
}
