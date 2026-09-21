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
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\ZoneMetadataStoreInterface;
use Poweradmin\Domain\Service\Zone\ZoneMetadataService;
use Poweradmin\Domain\Database\PdnsTable;
use Poweradmin\Domain\Database\TableNameService;

/**
 * Zone metadata in the PowerDNS domainmetadata table.
 */
class DbZoneMetadataStore implements ZoneMetadataStoreInterface
{
    private TableNameService $tableNameService;

    public function __construct(private readonly PDO $db, ConfigurationInterface $config)
    {
        $this->tableNameService = new TableNameService($config);
    }

    public function load(int $zoneId, string $zoneName): array
    {
        $table = $this->tableNameService->getTable(PdnsTable::DOMAINMETADATA);

        $stmt = $this->db->prepare("SELECT kind, content FROM $table WHERE domain_id = :domain_id ORDER BY kind, id");
        $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function replaceAll(int $zoneId, string $zoneName, array $rows, array $before): bool
    {
        $table = $this->tableNameService->getTable(PdnsTable::DOMAINMETADATA);

        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare("DELETE FROM $table WHERE domain_id = :domain_id");
            $delete->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
            $delete->execute();

            if ($rows !== []) {
                $insert = $this->db->prepare("INSERT INTO $table (domain_id, kind, content) VALUES (:domain_id, :kind, :content)");
                foreach ($rows as $row) {
                    $insert->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
                    $insert->bindValue(':kind', $row['kind'], PDO::PARAM_STR);
                    $insert->bindValue(':content', $row['content'], PDO::PARAM_STR);
                    $insert->execute();
                }
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function writeRejection(string $kind): ?string
    {
        return null;
    }

    public function kindSupport(array $definition, callable $capabilities): string
    {
        return self::SUPPORT_SUPPORTED;
    }

    public function replaceKind(int $zoneId, string $zoneName, string $kind, array $values, array $before): bool
    {
        return $this->replaceAll($zoneId, $zoneName, ZoneMetadataService::replaceKindIn($before, $kind, $values), $before);
    }
}
