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

namespace Poweradmin\Tests\Unit\Application\Controller\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\Zone\ZoneCatalogController;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\CatalogZoneService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The catalog members page checks zone access before it looks the zone up, so a
 * user who may not see a zone cannot tell a missing id from a foreign producer zone.
 */
#[CoversClass(ZoneCatalogController::class)]
class ZoneCatalogControllerAccessTest extends SeamControllerTestCase
{
    private const MISSING_ZONE_ID = 404;
    private const FOREIGN_PRODUCER_ID = 12;
    private const FOREIGN_PRIMARY_ID = 13;

    private string $metadataViewLevel = 'own';

    protected function setUp(): void
    {
        parent::setUp();

        $this->session->set('pdns_server_info', ['fetched_at' => time(), 'info' => ['version' => '4.9.0', 'daemon_type' => 'authoritative', 'id' => 'localhost']]);

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('userOwnsZone')->willReturn(false);
        $permissions->method('getZoneMetadataViewPermissionLevel')->willReturnCallback(fn(): string => $this->metadataViewLevel);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturnCallback(static fn(int $id): ?string => match ($id) {
            self::FOREIGN_PRODUCER_ID => 'catalog.example.',
            self::FOREIGN_PRIMARY_ID => 'example.com',
            default => null,
        });
        $domains->method('getDomainType')->willReturnCallback(static fn(int $id): string => match ($id) {
            self::FOREIGN_PRODUCER_ID => ZoneType::PRODUCER,
            self::FOREIGN_PRIMARY_ID => ZoneType::MASTER,
            default => '',
        });

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('catalogZoneService')->willReturn($this->createMock(CatalogZoneService::class));
    }

    /** @return array<string, array{int, bool}> */
    public static function hiddenZones(): array
    {
        return [
            'missing id' => [self::MISSING_ZONE_ID, false],
            'foreign producer zone' => [self::FOREIGN_PRODUCER_ID, false],
            'foreign primary zone' => [self::FOREIGN_PRIMARY_ID, false],
            'missing id, POST' => [self::MISSING_ZONE_ID, true],
            'foreign producer zone, POST' => [self::FOREIGN_PRODUCER_ID, true],
        ];
    }

    #[DataProvider('hiddenZones')]
    public function testAnOwnOnlyMetadataViewerGetsTheSameRefusalWhetherOrNotTheZoneExists(int $zoneId, bool $isPost): void
    {
        $this->assertSame('You do not have permission to access this zone.', $this->errorFor($zoneId, $isPost));
    }

    public function testAnAllMetadataViewerStillLearnsThatAnIdIsMissing(): void
    {
        $this->metadataViewLevel = 'all';

        $this->assertSame('Zone not found.', $this->errorFor(self::MISSING_ZONE_ID, false));
    }

    private function errorFor(int $zoneId, bool $isPost): string
    {
        $this->query(['id' => (string)$zoneId]);
        if ($isPost) {
            $this->post(['member_zone_id' => '7', 'add_member' => '1']);
        }
        $controller = new ZoneCatalogController($this->requestData(), true, $this->environment($this->configure()));

        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);

            return $halt->target;
        }

        $this->fail('Expected the request to end in an error page.');
    }
}
