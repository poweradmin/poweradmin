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
use Poweradmin\Application\Controller\Api\V1\ZonesRecordsController as V1ZonesRecordsController;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsBulkController;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsController;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

/**
 * An update that names the apex as "@" must reach the record manager as the zone
 * name, because the hostname validator no longer treats "@" as a valid name.
 */
class ZonesRecordsApexNameUpdateTest extends TestCase
{
    private const ZONE_ID = 7;
    private const ZONE_NAME = 'example.com';

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function controllerProvider(): array
    {
        return [
            'v2 put' => [ZonesRecordsController::class, 'put'],
            'v2 bulk' => [ZonesRecordsBulkController::class, 'bulk'],
            'v1 put' => [V1ZonesRecordsController::class, 'put'],
        ];
    }

    #[Test]
    #[DataProvider('controllerProvider')]
    public function anApexMarkerNameIsResolvedToTheZoneName(string $class, string $mode): void
    {
        $this->assertSame(self::ZONE_NAME, $this->submittedName($class, $mode, '@'));
    }

    #[Test]
    #[DataProvider('controllerProvider')]
    public function aNameWithinTheZoneIsKept(string $class, string $mode): void
    {
        $this->assertSame('www.example.com', $this->submittedName($class, $mode, 'www.example.com'));
    }

    /**
     * @param class-string $class
     */
    private function submittedName(string $class, string $mode, string $name): ?string
    {
        $existing = [
            'id' => 5,
            'domain_id' => self::ZONE_ID,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.1',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ];

        $zones = $this->createMock(DbZoneRepository::class);
        $zones->method('getZoneById')->willReturn(['id' => self::ZONE_ID, 'name' => self::ZONE_NAME, 'type' => 'MASTER']);
        $records = $this->createMock(RecordRepository::class);
        $records->method('getRecordById')->willReturn($existing);
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneContent')->willReturn(true);
        $permissions->method('canEditZoneRecord')->willReturn(true);

        $submitted = null;
        $manager = $this->createMock(RecordManagerInterface::class);
        $manager->method('editRecord')->willReturnCallback(function (array $data) use (&$submitted): bool {
            $submitted = $data['name'];
            return false;
        });

        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $properties = [
            'zoneRepository' => $zones,
            'recordRepository' => $records,
            'permissionService' => $permissions,
            'recordManager' => $manager,
            'pathParameters' => ['id' => (string)self::ZONE_ID, 'record_id' => '5'],
            'request' => Request::create('/', 'PUT', [], [], [], [], (string)json_encode(['name' => $name])),
        ];
        foreach ($properties as $property => $value) {
            $this->setProperty($controller, $property, $value);
        }

        if ($mode === 'bulk') {
            try {
                (new ReflectionMethod($class, 'performUpdateOperation'))->invoke(
                    $controller,
                    self::ZONE_ID,
                    ['id' => 5, 'name' => $name],
                    'MASTER'
                );
            } catch (\Throwable) {
                // The stub refuses the edit after capturing the name
            }
        } else {
            try {
                (new ReflectionMethod($class, 'updateRecord'))->invoke($controller);
            } catch (\Throwable) {
                // The stub refuses the edit after capturing the name
            }
        }

        return $submitted;
    }

    private function setProperty(object $controller, string $property, mixed $value): void
    {
        $class = new ReflectionClass($controller);
        while ($class && !$class->hasProperty($property)) {
            $class = $class->getParentClass();
        }
        if (!$class) {
            return;
        }
        (new ReflectionProperty($class->getName(), $property))->setValue($controller, $value);
    }
}
