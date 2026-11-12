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
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

/**
 * A PUT that omits content falls back to the stored value, which may predate the
 * control character check; submitted content is trimmed.
 */
class ZonesRecordsContentHandlingUpdateTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function controllerProvider(): array
    {
        return [
            'v2 put' => [ZonesRecordsController::class],
            'v1 put' => [V1ZonesRecordsController::class],
            'v2 bulk update' => [ZonesRecordsBulkController::class],
        ];
    }

    #[Test]
    #[DataProvider('controllerProvider')]
    public function storedContentWithControlCharactersIsHealedWhenTheBodyOmitsContent(string $class): void
    {
        $this->assertSame('192.0.2.1', $this->submittedContent($class, "192.0.2.1\r", ['ttl' => 300]));
    }

    #[Test]
    #[DataProvider('controllerProvider')]
    public function submittedContentIsTrimmed(string $class): void
    {
        $this->assertSame('192.0.2.9', $this->submittedContent($class, '192.0.2.1', ['content' => "192.0.2.9\n"]));
    }

    #[Test]
    #[DataProvider('controllerProvider')]
    public function storedTabInCaaContentIsHealed(string $class): void
    {
        $this->assertSame('0 issue "ca.example"', $this->submittedContent($class, "0 issue\t\"ca.example\"", ['ttl' => 300], 'CAA'));
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $body
     */
    private function submittedContent(string $class, string $storedContent, array $body, string $type = 'A'): ?string
    {
        $existing = [
            'id' => 5,
            'domain_id' => 7,
            'name' => 'www.example.com',
            'type' => $type,
            'content' => $storedContent,
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ];

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZoneById')->willReturn(['id' => 7, 'name' => 'example.com', 'type' => 'MASTER']);
        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getRecordById')->willReturn($existing);
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneContent')->willReturn(true);
        $permissions->method('canEditZoneRecord')->willReturn(true);
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('getZoneIdByName')->willReturn(7);

        $submitted = null;
        $manager = $this->createMock(RecordManagerInterface::class);
        $manager->method('editRecord')->willReturnCallback(function (array $data) use (&$submitted): bool {
            $submitted = $data['content'];
            return false;
        });

        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $properties = [
            'zoneRepository' => $zones,
            'recordRepository' => $records,
            'permissionService' => $permissions,
            'backendProvider' => $backend,
            'recordManager' => $manager,
            'pathParameters' => ['id' => '7', 'record_id' => '5'],
            'authenticatedUserId' => 1,
            'request' => Request::create('/', 'PUT', [], [], [], [], (string)json_encode($body)),
        ];
        foreach ($properties as $property => $value) {
            $this->setProperty($controller, $property, $value);
        }

        try {
            if ($class === ZonesRecordsBulkController::class) {
                (new ReflectionMethod($class, 'performUpdateOperation'))->invoke($controller, 7, ['id' => 5] + $body, 'MASTER', 'example.com');
            } else {
                (new ReflectionMethod($class, 'updateRecord'))->invoke($controller);
            }
        } catch (\Throwable) {
            // The stub refuses the edit after capturing the content
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
