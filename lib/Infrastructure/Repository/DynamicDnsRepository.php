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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\DynamicDnsRepositoryInterface;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\ValueObject\HostnameValue;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;

class DynamicDnsRepository implements DynamicDnsRepositoryInterface
{
    private PDO $db;
    private DnsRecord $dnsRecord;
    private string $recordsTable;
    private ConfigurationManager $config;

    public function __construct(PDO $db, DnsRecord $dnsRecord, string $recordsTable, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->dnsRecord = $dnsRecord;
        $this->config = $config;
        $this->recordsTable = $recordsTable;
    }

    public function findUserByUsernameWithDynamicDnsPermissions(string $username): ?User
    {
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
        $query = $this->db->prepare('SELECT domain_id FROM zones WHERE owner = :user_id');
        $query->execute([':user_id' => $user->getId()]);

        $zones = [];
        while ($zone = $query->fetch(PDO::FETCH_ASSOC)) {
            $zones[] = (int)$zone['domain_id'];
        }

        return $zones;
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

    public function deleteDnsRecord(int $recordId): void
    {
        $delete = $this->db->prepare("DELETE FROM {$this->recordsTable} WHERE id = :id");
        $delete->execute([':id' => $recordId]);
    }

    public function updateSOASerial(int $zoneId): void
    {
        $this->dnsRecord->updateSOASerial($zoneId);
    }
}
