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

use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Repository\ZoneMetadataStoreInterface;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

/**
 * Zone metadata behind the PowerDNS API: metadata kinds through the metadata
 * endpoints, and the kinds PowerDNS exposes as zone-object properties through
 * a zone update.
 */
class ApiZoneMetadataStore implements ZoneMetadataStoreInterface
{
    public function __construct(private readonly PowerdnsApiClient $apiClient)
    {
    }

    public function load(int $zoneId, string $zoneName): array
    {
        $apiName = self::apiZoneName($zoneName);
        $rows = MetadataDefinitions::rowsFromApiPayload(
            $this->apiClient->getZoneMetadata(new Zone($apiName)),
            $this->apiClient->getZone($apiName, false)
        );
        usort($rows, fn(array $a, array $b): int => strcmp($a['kind'], $b['kind']));

        return $rows;
    }

    public function replaceAll(int $zoneId, string $zoneName, array $rows, array $before): bool
    {
        $apiName = self::apiZoneName($zoneName);
        $zone = new Zone($apiName);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['kind']][] = $row['content'];
        }
        $beforeByKind = array_flip(array_column($before, 'kind'));

        // Zone-object-backed kinds are set, or cleared when they left the set,
        // through one zone properties update.
        $properties = [];
        foreach (MetadataDefinitions::ZONE_PROPERTY_KINDS as $kind => $property) {
            if (isset($grouped[$kind])) {
                $properties[$property] = MetadataDefinitions::toZonePropertyValue($kind, $grouped[$kind][0]);
                unset($grouped[$kind]);
            } elseif (isset($beforeByKind[$kind])) {
                $properties[$property] = MetadataDefinitions::toZonePropertyValue($kind, '');
            }
        }

        $success = true;
        if ($properties !== []) {
            $success = $this->apiClient->updateZoneProperties($apiName, $properties);
        }

        // Kinds the API cannot store never reach here changed; unchanged ones are left alone
        foreach ($grouped as $kind => $values) {
            if (MetadataDefinitions::writeRejection((string)$kind, true) !== null) {
                continue;
            }
            $success = $this->apiClient->updateZoneMetadata($zone, (string)$kind, $values) && $success;
        }
        foreach (array_keys($beforeByKind) as $kind) {
            if (isset($grouped[$kind]) || isset(MetadataDefinitions::ZONE_PROPERTY_KINDS[$kind]) || MetadataDefinitions::writeRejection((string)$kind, true) !== null) {
                continue;
            }
            $success = $this->apiClient->deleteZoneMetadata($zone, (string)$kind) && $success;
        }

        return $success;
    }

    public function replaceKind(int $zoneId, string $zoneName, string $kind, array $values, array $before): bool
    {
        $apiName = self::apiZoneName($zoneName);
        $property = MetadataDefinitions::ZONE_PROPERTY_KINDS[$kind] ?? null;
        if ($property !== null) {
            return $this->apiClient->updateZoneProperties($apiName, [
                $property => MetadataDefinitions::toZonePropertyValue($kind, $values[0] ?? ''),
            ]);
        }
        if ($values === []) {
            return $this->apiClient->deleteZoneMetadata(new Zone($apiName), $kind);
        }

        return $this->apiClient->updateZoneMetadata(new Zone($apiName), $kind, $values);
    }

    /** The PowerDNS API wants the absolute name */
    private static function apiZoneName(string $zoneName): string
    {
        return str_ends_with($zoneName, '.') ? $zoneName : $zoneName . '.';
    }
}
