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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\DeleteRecordController;
use Poweradmin\Application\Controller\EditRecordController;
use Poweradmin\Application\Controller\EditZoneMetadataController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Module\DnsWizard\Controller\DnsWizardFormController;
use Poweradmin\Module\DnsWizard\Controller\DnsWizardSelectController;
use Poweradmin\Module\ZoneImportExport\Controller\ZoneFileImportController;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Pages that take a zone or record id check the user's access before they look the
 * id up, so a user who may not touch a zone cannot tell a missing id from a foreign one.
 */
class ZoneAccessBeforeLookupTest extends TestCase
{
    private const USER_ID = 7;
    private const MISSING_ID = 404;

    private array $savedSession = [];
    private array $savedPost = [];
    private mixed $savedRequestMethod = null;
    private ?PermissionService $savedPermissionMemo = null;

    /** @var string[] */
    private array $granted = [];

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
        $this->savedPost = $_POST;
        $this->savedRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SESSION[SessionKeys::USERID] = self::USER_ID;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Permission's static helpers memoize one PermissionService; swap in a stub
        $memo = new ReflectionProperty(Permission::class, 'permissionService');
        $this->savedPermissionMemo = $memo->getValue();
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(fn(int $userId, string $name): bool => in_array($name, $this->granted, true));
        $memo->setValue(null, $permissions);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        $_POST = $this->savedPost;
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
        (new ReflectionProperty(Permission::class, 'permissionService'))->setValue(null, $this->savedPermissionMemo);
    }

    public function testZoneMetadataRefusesAMissingIdLikeAForeignZone(): void
    {
        $this->granted = ['zone_metadata_view_own'];

        $this->assertSame('You do not have the permission to view zone metadata.', $this->zoneMetadataError());
    }

    public function testZoneMetadataStillTellsAnAllViewerThatTheZoneIsMissing(): void
    {
        $this->granted = ['zone_metadata_view_others'];

        $this->assertSame('Zone not found.', $this->zoneMetadataError());
    }

    public function testDeleteRecordRefusesAMissingRecordLikeAForeignOne(): void
    {
        $this->assertSame('You do not have permission to delete records in this zone.', $this->deleteRecordError('own'));
    }

    public function testDeleteRecordStillTellsAnAllEditorThatTheRecordIsMissing(): void
    {
        $this->assertSame('Invalid record ID.', $this->deleteRecordError('all'));
    }

    public function testEditRecordRefusesAMissingRecordLikeAForeignOne(): void
    {
        $this->assertSame('You do not have permission to view this record.', $this->editRecordError(false));
    }

    public function testEditRecordStillTellsAnAllViewerThatTheRecordIsMissing(): void
    {
        $this->assertSame('Invalid record ID.', $this->editRecordError(true));
    }

    public function testDnsWizardSelectRefusesAMissingZoneLikeAForeignOne(): void
    {
        $this->granted = ['zone_content_edit_own'];

        $this->assertSame('You do not have permission to add records to this zone.', $this->wizardError(DnsWizardSelectController::class));
    }

    public function testDnsWizardFormRefusesAMissingZoneLikeAForeignOne(): void
    {
        $this->granted = ['zone_content_edit_own'];

        $this->assertSame('You do not have permission to add records to this zone.', $this->wizardError(DnsWizardFormController::class));
    }

    public function testDnsWizardStillTellsAnAllEditorThatTheZoneIsMissing(): void
    {
        $this->granted = ['zone_content_edit_others'];

        $this->assertSame('Zone not found.', $this->wizardError(DnsWizardSelectController::class));
    }

    public function testZoneFileImportRefusesAMissingZoneLikeAForeignOne(): void
    {
        $this->assertSame('You do not have permission to modify this zone.', $this->importError('none'));
    }

    public function testZoneFileImportStillTellsAnEditorThatTheZoneIsMissing(): void
    {
        $this->assertSame('The selected zone does not exist.', $this->importError('all'));
    }

    private function zoneMetadataError(): string
    {
        $controller = new class (['id' => (string)self::MISSING_ID]) extends EditZoneMetadataController {
            use ControllerTestStubs;
        };
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZone')->willReturn(null);
        $this->inject($controller, EditZoneMetadataController::class, ['zoneRepository' => $zones]);

        return $this->haltMessage(fn() => $controller->run());
    }

    private function deleteRecordError(string $editLevel): string
    {
        $controller = new class (['id' => (string)self::MISSING_ID]) extends DeleteRecordController {
            use ControllerTestStubs;
        };
        $controller->records = $this->missingRecords();
        $controller->recordManager = $this->createMock(RecordManagerInterface::class);
        $controller->domains = $this->createMock(DomainRepositoryInterface::class);

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevel')->willReturn($editLevel);
        $permissions->method('getEditPermissionLevelForZone')->willReturn($editLevel);
        $this->inject($controller, DeleteRecordController::class, [
            'permissionService' => $permissions,
            'userContextService' => $this->userContext(),
        ]);

        return $this->haltMessage(fn() => $controller->run());
    }

    private function editRecordError(bool $viewOthers): string
    {
        $controller = new class (['id' => (string)self::MISSING_ID]) extends EditRecordController {
            use ControllerTestStubs;
        };
        $controller->records = $this->missingRecords();
        $controller->domains = $this->createMock(DomainRepositoryInterface::class);
        $controller->permissions = $viewOthers ? ['zone_content_view_others'] : [];
        $this->inject($controller, EditRecordController::class, [
            'permissionService' => $this->createMock(PermissionService::class),
            'userContextService' => $this->userContext(),
        ]);

        return $this->haltMessage(fn() => $controller->run());
    }

    /**
     * @param class-string $controllerClass
     */
    private function wizardError(string $controllerClass): string
    {
        $request = ['id' => (string)self::MISSING_ID, 'type' => 'spf'];
        $controller = $controllerClass === DnsWizardSelectController::class
            ? new class ($request) extends DnsWizardSelectController {
                use ControllerTestStubs;
            }
            : new class ($request) extends DnsWizardFormController {
                use ControllerTestStubs;
            };

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $key === 'dns_wizards.enabled' ? true : $default);
        $controller->setConfig($config);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getDomainNameById')->willReturn(null);
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainType')->willReturn('MASTER');
        $this->inject($controller, $controllerClass, ['zoneRepository' => $zones, 'domainRepository' => $domains]);

        return $this->haltMessage(fn() => $controller->run());
    }

    private function importError(string $editLevel): string
    {
        $_SESSION[SessionKeys::ZONE_IMPORT_DATA] = ['records' => '[]', 'origin' => 'example.com'];
        $_POST = ['import_mode' => 'existing', 'existing_zone_id' => (string)self::MISSING_ID];

        $controller = new class ([]) extends ZoneFileImportController {
            use ControllerTestStubs;
        };
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevelForZone')->willReturn($editLevel);
        $controller->permissionServiceStub = $permissions;
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getDomainNameById')->willReturn(null);
        $controller->zones = $zones;
        $controller->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->inject($controller, ZoneFileImportController::class, ['userContextService' => $this->userContext()]);

        return $this->haltMessage(fn() => (new ReflectionMethod(ZoneFileImportController::class, 'handleExecute'))->invoke($controller));
    }

    private function missingRecords(): RecordRepositoryInterface
    {
        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getZoneIdFromRecordId')->willReturn(0);

        return $records;
    }

    private function userContext(): UserContextService
    {
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(self::USER_ID);

        return $userContext;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function inject(object $controller, string $declaringClass, array $values): void
    {
        foreach ($values as $name => $value) {
            (new ReflectionProperty($declaringClass, $name))->setValue($controller, $value);
        }
    }

    private function haltMessage(callable $action): string
    {
        try {
            $action();
        } catch (RuntimeException $halt) {
            return $halt->getMessage();
        }

        $this->fail('Expected the request to end in an error page.');
    }
}
