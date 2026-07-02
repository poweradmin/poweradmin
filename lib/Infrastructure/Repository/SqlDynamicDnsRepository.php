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
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\ValueObject\HostnameValue;

/**
 * SQL-backend dynamic DNS repository.
 */
class SqlDynamicDnsRepository implements DynamicDnsRepositoryInterface
{
    public function __construct(
        private readonly PDO $db,
        private readonly SOARecordManagerInterface $soaRecordManager,
        private readonly string $recordsTable,
        private readonly string $domainsTable
    ) {
    }

    public function findUserByUsernameWithDynamicDnsPermissions(string $username): ?User
    {
        // DDNS auth requires an explicit zone_content_edit_* grant; ueberuser bypass is
        // deliberately omitted so admin credentials never have to live in ddclient.conf.
        $query = $this->db->prepare("
            SELECT users.id, users.password, users.use_ldap
            FROM users, perm_templ, perm_templ_items, perm_items
            WHERE users.username = :username
                AND users.active = 1
                AND perm_templ.id = users.perm_templ
                AND perm_templ_items.templ_id = perm_templ.id
                AND perm_items.id = perm_templ_items.perm_id
                AND (
                    perm_items.name = 'zone_content_edit_own'
                    OR perm_items.name = 'zone_content_edit_own_as_client'
                    OR perm_items.name = 'zone_content_edit_others'
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
        // Domain names come from the PowerDNS-owned `domains` table; needed by the service
        // to longest-suffix-match the supplied hostname against the user's owned zones.
        $query = $this->db->prepare("
            SELECT d.id AS domain_id, d.name AS name
            FROM {$this->domainsTable} d
            INNER JOIN zones z ON z.domain_id = d.id
            WHERE z.owner = :user_id

            UNION

            SELECT d.id AS domain_id, d.name AS name
            FROM {$this->domainsTable} d
            INNER JOIN zones_groups zg ON zg.domain_id = d.id
            INNER JOIN user_group_members ugm ON ugm.group_id = zg.group_id
            WHERE ugm.user_id = :user_id2
        ");
        $query->execute([
            ':user_id' => $user->getId(),
            ':user_id2' => $user->getId(),
        ]);

        $zones = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $zones[(int)$row['domain_id']] = (string)$row['name'];
        }

        return $zones;
    }

    public function getZoneType(int $zoneId): ?string
    {
        $query = $this->db->prepare("SELECT type FROM {$this->domainsTable} WHERE id = :id");
        $query->execute([':id' => $zoneId]);
        $type = $query->fetchColumn();

        return $type === false ? null : (string)$type;
    }

    public function getDnsRecords(int $zoneId, HostnameValue $hostname, string $recordType): array
    {
        $query = $this->db->prepare("
            SELECT id, content
            FROM {$this->recordsTable}
            WHERE domain_id = :domain_id AND name = :hostname AND type = :type
        ");
        $query->execute([
            ':domain_id' => $zoneId,
            ':hostname' => $hostname->getValue(),
            ':type' => $recordType
        ]);

        $records = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $records[$row['content']] = (int)$row['id'];
        }

        return $records;
    }

    public function insertDnsRecord(int $zoneId, HostnameValue $hostname, string $recordType, string $content): void
    {
        $insert = $this->db->prepare("
            INSERT INTO {$this->recordsTable} (domain_id, name, type, content, ttl, prio)
            VALUES (:domain_id, :hostname, :type, :content, 60, NULL)
        ");
        $insert->execute([
            ':domain_id' => $zoneId,
            ':hostname' => $hostname->getValue(),
            ':type' => $recordType,
            ':content' => $content
        ]);
    }

    public function deleteDnsRecord(int|string $recordId): void
    {
        $delete = $this->db->prepare("DELETE FROM {$this->recordsTable} WHERE id = :id");
        $delete->execute([':id' => $recordId]);
    }

    public function updateSOASerial(int $zoneId): void
    {
        $this->soaRecordManager->updateSOASerial($zoneId);
    }
}
