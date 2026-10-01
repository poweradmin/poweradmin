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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsBulkController;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use ReflectionClass;
use ReflectionMethod;

/**
 * Record lookups by id must accept a zone addressed by its zones row id when its
 * records carry the canonical id (a zone moved from SQL to API backend mode, gh #1578).
 */
class ZonesRecordsZoneMatchTest extends TestCase
{
    private const ROW_ID = 24;
    private const CANONICAL_ID = 14;
    private const ZONE_NAME = 'example.com';

    /**
     * @return array<string, array{class-string}>
     */
    public static function controllerProvider(): array
    {
        return [
            'records' => [ZonesRecordsController::class],
            'bulk' => [ZonesRecordsBulkController::class],
        ];
    }

    #[Test]
    #[DataProvider('controllerProvider')]
    public function aRecordOfTheAddressedZoneMatches(string $class): void
    {
        $this->assertTrue($this->belongsToZone($class, ['domain_id' => self::ROW_ID], self::ROW_ID, self::ZONE_NAME));
    }

    #[Test]
    #[DataProvider('controllerProvider')]
    public function aRecordCarryingTheZonesCanonicalIdMatchesThroughTheRowId(string $class): void
    {
        $this->assertTrue($this->belongsToZone($class, ['domain_id' => self::CANONICAL_ID], self::ROW_ID, self::ZONE_NAME));
    }

    #[Test]
    #[DataProvider('controllerProvider')]
    public function theZoneNameIsLookedUpWhenTheCallerHasNone(string $class): void
    {
        $this->assertTrue($this->belongsToZone($class, ['domain_id' => self::CANONICAL_ID], self::ROW_ID, null));
    }

    #[Test]
    #[DataProvider('controllerProvider')]
    public function aRecordOfAnotherZoneDoesNotMatch(string $class): void
    {
        $this->assertFalse($this->belongsToZone($class, ['domain_id' => 999], self::ROW_ID, self::ZONE_NAME));
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $record
     */
    private function belongsToZone(string $class, array $record, int $zoneId, ?string $zoneName): bool
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZoneById')->willReturn(['id' => self::ROW_ID, 'name' => self::ZONE_NAME]);
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('getZoneIdByName')->willReturnCallback(
            fn(string $name): ?int => $name === self::ZONE_NAME ? self::CANONICAL_ID : null
        );

        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach (['zoneRepository' => $zones, 'backendProvider' => $backend] as $property => $value) {
            $reflection = new \ReflectionProperty($class, $property);
            $reflection->setValue($controller, $value);
        }

        return (new ReflectionMethod($class, 'recordBelongsToZone'))->invoke($controller, $record, $zoneId, $zoneName);
    }
}
