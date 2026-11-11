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
use Poweradmin\Application\Controller\BatchPtrRecordController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionProperty;
use RuntimeException;

/**
 * The batch PTR page checks the user's access to a zone before it looks the zone up,
 * so a missing id, a foreign reverse zone and a foreign forward zone all answer alike.
 */
class BatchPtrRecordControllerAccessTest extends TestCase
{
    private const MISSING_ZONE_ID = 404;
    private const FOREIGN_REVERSE_ZONE_ID = 12;
    private const FORWARD_ZONE_ID = 13;

    private array $savedGet = [];
    private mixed $savedRequestMethod = null;

    protected function setUp(): void
    {
        $this->savedGet = $_GET;
        $this->savedRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_GET = $this->savedGet;
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    }

    public function testAMissingZoneGetsThePermissionRefusal(): void
    {
        $this->assertSame('You do not have permission to add records to this zone.', $this->errorFor(self::MISSING_ZONE_ID, 'none'));
    }

    public function testAForeignReverseZoneGetsThePermissionRefusalNotItsKind(): void
    {
        $this->assertSame('You do not have permission to add records to this zone.', $this->errorFor(self::FOREIGN_REVERSE_ZONE_ID, 'none'));
    }

    public function testAnEditorStillLearnsThatAnIdIsMissing(): void
    {
        $this->assertSame('There is no zone with this ID.', $this->errorFor(self::MISSING_ZONE_ID, 'all'));
    }

    public function testAnEditorIsStillToldThatReverseZonesAreNotSupported(): void
    {
        $this->assertSame('Batch PTR record creation is not available for reverse zones.', $this->errorFor(self::FOREIGN_REVERSE_ZONE_ID, 'own'));
    }

    public function testAnEditorIsStillRefusedOnAReadOnlyZone(): void
    {
        $this->assertSame('You do not have permission to add records to this zone.', $this->errorFor(self::FORWARD_ZONE_ID, 'own'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function forwardZonesTheCallerCannotUse(): array
    {
        return [
            'matching, foreign zone' => ['only_matching_records', 'foreign.example'],
            'matching, foreign zone typed in capitals' => ['only_matching_records', ' FOREIGN.example '],
            'matching, missing zone' => ['only_matching_records', 'missing.example'],
            'forward records, foreign zone' => ['create_forward_records', 'foreign.example'],
        ];
    }

    #[DataProvider('forwardZonesTheCallerCannotUse')]
    public function testTheForwardZoneIsRefusedUnlessTheCallerCanEditIt(string $flag, string $domain): void
    {
        $this->assertSame(
            [['error', 'You do not have permission to add records to this zone.']],
            $this->forwardZoneMessages($flag, $domain, 'none')
        );
    }

    public function testAnEditorOfTheForwardZoneIsNotRefused(): void
    {
        $this->assertSame([], $this->forwardZoneMessages('only_matching_records', 'EXAMPLE.com', 'own'));
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function forwardZoneMessages(string $flag, string $domain, string $editLevel): array
    {
        $_POST = ['network_type' => 'ipv4', 'network_prefix' => '192.0.2.0/24', 'domain' => $domain, $flag => 'on'];

        $controller = new class ([]) extends BatchPtrRecordController {
            use ControllerTestStubs;

            /** @var array<int, array{0: string, 1: string}> */
            public array $messages = [];

            public function setMessage(string $script, string $type, string $content): void
            {
                $this->messages[] = [$type, $content];
            }
        };
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $default);
        $controller->setConfig($config);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainIdByName')->willReturnCallback(static fn(string $name): ?int => match ($name) {
            'example.com' => self::FORWARD_ZONE_ID,
            'foreign.example' => 99,
            default => null,
        });
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevelForZone')->willReturn($editLevel);
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);

        $values = [
            'request' => new Request(),
            'domainRepository' => $domains,
            'permissionService' => $permissions,
            'userContextService' => $userContext,
        ];
        foreach ($values as $name => $value) {
            (new ReflectionProperty(BatchPtrRecordController::class, $name))->setValue($controller, $value);
        }

        try {
            (new \ReflectionMethod(BatchPtrRecordController::class, 'addBatchPtrRecords'))->invoke($controller);
        } catch (\Throwable) {
            // Past the access check the unwired controller trips on its missing services.
        }

        return $controller->messages;
    }

    private function errorFor(int $zoneId, string $editLevel): string
    {
        $_GET = ['id' => (string)$zoneId];

        $controller = new class ([]) extends BatchPtrRecordController {
            use ControllerTestStubs;
        };
        $controller->permissions = ['zone_content_edit_own'];

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $default);
        $controller->setConfig($config);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturnCallback(static fn(int $id): ?string => match ($id) {
            self::FOREIGN_REVERSE_ZONE_ID => '2.0.192.in-addr.arpa',
            self::FORWARD_ZONE_ID => 'example.com',
            default => null,
        });
        $domains->method('getDomainType')->willReturn('SLAVE');

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevelForZone')->willReturn($editLevel);

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);

        $values = [
            'request' => new Request(),
            'domainRepository' => $domains,
            'permissionService' => $permissions,
            'userContextService' => $userContext,
        ];
        foreach ($values as $name => $value) {
            (new ReflectionProperty(BatchPtrRecordController::class, $name))->setValue($controller, $value);
        }

        try {
            $controller->run();
        } catch (RuntimeException $halt) {
            return $halt->getMessage();
        }

        $this->fail('Expected the request to end in an error page.');
    }
}
