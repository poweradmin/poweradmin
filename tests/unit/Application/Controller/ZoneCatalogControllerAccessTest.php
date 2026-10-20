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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\ZoneCatalogController;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\PdnsCapabilities;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use ReflectionProperty;
use RuntimeException;

/**
 * The catalog members page checks zone access before it looks the zone up, so a
 * user who may not see a zone cannot tell a missing id from a foreign producer zone.
 */
class ZoneCatalogControllerAccessTest extends TestCase
{
    private const MISSING_ZONE_ID = 404;
    private const FOREIGN_PRODUCER_ID = 12;
    private const FOREIGN_PRIMARY_ID = 13;

    private mixed $savedRequestMethod = null;

    protected function setUp(): void
    {
        $this->savedRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    }

    /** @return array<string, array{int}> */
    public static function hiddenZones(): array
    {
        return [
            'missing id' => [self::MISSING_ZONE_ID],
            'foreign producer zone' => [self::FOREIGN_PRODUCER_ID],
            'foreign primary zone' => [self::FOREIGN_PRIMARY_ID],
        ];
    }

    #[DataProvider('hiddenZones')]
    public function testAnOwnOnlyMetadataViewerGetsTheSameRefusalWhetherOrNotTheZoneExists(int $zoneId): void
    {
        $this->assertSame('You do not have permission to access this zone.', $this->errorFor($zoneId, 'own'));
    }

    public function testAnAllMetadataViewerStillLearnsThatAnIdIsMissing(): void
    {
        $this->assertSame('Zone not found.', $this->errorFor(self::MISSING_ZONE_ID, 'all'));
    }

    public function testAnAllMetadataViewerStillLearnsThatAZoneIsNotAProducer(): void
    {
        $this->assertSame('Only producer zones publish a catalog.', $this->errorFor(self::FOREIGN_PRIMARY_ID, 'all'));
    }

    private function errorFor(int $zoneId, string $metadataViewLevel): string
    {
        $controller = new class (['id' => (string)$zoneId]) extends ZoneCatalogController {
            public function __construct(array $request)
            {
                $this->requestData = $request;
            }

            public function showError(string $error, ?string $recordName = null): void
            {
                throw new RuntimeException($error);
            }

            protected function refreshPdnsCapabilities(): void
            {
            }

            protected function getPdnsCapabilities(): PdnsCapabilities
            {
                return PdnsCapabilities::fromVersion('4.9.0');
            }

            protected function isZoneOwner(int $zoneId): bool
            {
                return false;
            }
        };

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getDomainNameById')->willReturnCallback(static fn(int $id): ?string => match ($id) {
            self::FOREIGN_PRODUCER_ID => 'catalog.example.',
            self::FOREIGN_PRIMARY_ID => 'example.com',
            default => null,
        });
        $zones->method('getDomainType')->willReturnCallback(static fn(int $id): string => match ($id) {
            self::FOREIGN_PRODUCER_ID => ZoneType::PRODUCER,
            self::FOREIGN_PRIMARY_ID => ZoneType::MASTER,
            default => '',
        });

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getZoneMetadataViewPermissionLevel')->willReturn($metadataViewLevel);

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);

        foreach (['zoneRepository' => $zones, 'permissionService' => $permissions, 'userContextService' => $userContext] as $name => $value) {
            (new ReflectionProperty(ZoneCatalogController::class, $name))->setValue($controller, $value);
        }

        try {
            $controller->run();
        } catch (RuntimeException $halt) {
            return $halt->getMessage();
        }

        $this->fail('Expected the request to end in an error page.');
    }
}
