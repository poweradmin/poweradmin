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

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Application\Controller\DeleteRecordController;
use Poweradmin\Application\Controller\EditRecordController;
use Poweradmin\Application\Controller\EditZoneMetadataController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Module\DnsWizard\Controller\DnsWizardFormController;
use Poweradmin\Module\DnsWizard\Controller\DnsWizardSelectController;
use Poweradmin\Module\ZoneImportExport\Controller\ZoneFileImportController;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Pages that take a zone or record id check the user's access before they look the
 * id up, so a user who may not touch a zone cannot tell a missing id from a foreign one.
 *
 * UserManager::verifyPermission() caches the permission set in a static, so every
 * test runs in its own process.
 */
class ZoneAccessBeforeLookupTest extends SqliteIntegrationTestCase
{
    private const USER_ID = 100;
    private const PERM_TEMPL_ID = 100;
    private const MISSING_ID = 404;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        // PermissionService reads the full user row
        foreach (['fullname', 'email', 'description', 'use_ldap', 'auth_method'] as $column) {
            $this->db->exec("ALTER TABLE users ADD COLUMN $column TEXT");
        }
        $this->db->exec("ALTER TABLE users ADD COLUMN active INTEGER NOT NULL DEFAULT 1");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::PERM_TEMPL_ID . ", 'Tester')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ID . ", 'tester', " . self::PERM_TEMPL_ID . ")");

        $_SESSION['userid'] = self::USER_ID;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    #[RunInSeparateProcess]
    public function testZoneMetadataRefusesAMissingIdLikeAForeignZone(): void
    {
        $this->grant(['zone_content_view_own']);

        $this->assertSame('You do not have the permission to view zone metadata.', $this->zoneMetadataError());
    }

    #[RunInSeparateProcess]
    public function testZoneMetadataStillTellsAnAllViewerThatTheZoneIsMissing(): void
    {
        $this->grant(['zone_content_view_others']);

        $this->assertSame('Zone not found.', $this->zoneMetadataError());
    }

    #[RunInSeparateProcess]
    public function testDeleteRecordRefusesAMissingRecordLikeAForeignOne(): void
    {
        $this->assertSame('You do not have permission to delete records in this zone.', $this->deleteRecordError('own'));
    }

    #[RunInSeparateProcess]
    public function testDeleteRecordStillTellsAnAllEditorThatTheRecordIsMissing(): void
    {
        $this->assertSame('Invalid record ID.', $this->deleteRecordError('all'));
    }

    #[RunInSeparateProcess]
    public function testEditRecordRefusesAMissingRecordLikeAForeignOne(): void
    {
        $this->grant(['zone_content_view_own']);

        $this->assertSame('You do not have permission to view this record.', $this->editRecordError());
    }

    #[RunInSeparateProcess]
    public function testEditRecordStillTellsAnAllViewerThatTheRecordIsMissing(): void
    {
        $this->grant(['zone_content_view_others']);

        $this->assertSame('Invalid record ID.', $this->editRecordError());
    }

    #[RunInSeparateProcess]
    public function testDnsWizardSelectRefusesAMissingZoneLikeAForeignOne(): void
    {
        $this->grant(['zone_content_edit_own']);

        $this->assertSame('You do not have permission to add records to this zone.', $this->wizardError(DnsWizardSelectController::class));
    }

    #[RunInSeparateProcess]
    public function testDnsWizardFormRefusesAMissingZoneLikeAForeignOne(): void
    {
        $this->grant(['zone_content_edit_own']);

        $this->assertSame('You do not have permission to add records to this zone.', $this->wizardError(DnsWizardFormController::class));
    }

    #[RunInSeparateProcess]
    public function testDnsWizardStillTellsAnAllEditorThatTheZoneIsMissing(): void
    {
        $this->grant(['zone_content_edit_others']);

        $this->assertSame('Zone not found.', $this->wizardError(DnsWizardSelectController::class));
    }

    #[RunInSeparateProcess]
    public function testZoneFileImportRefusesAMissingZoneLikeAForeignOne(): void
    {
        $this->grant(['zone_content_edit_own']);

        $this->assertSame('You do not have permission to modify this zone.', $this->importError());
    }

    #[RunInSeparateProcess]
    public function testZoneFileImportStillTellsAnAllEditorThatTheZoneIsMissing(): void
    {
        $this->grant(['zone_content_edit_others']);

        $this->assertSame('The selected zone does not exist.', $this->importError());
    }

    /**
     * @param string[] $permissions
     */
    private function grant(array $permissions): void
    {
        $stmt = $this->db->prepare("INSERT INTO perm_items (name) VALUES (?)");
        $link = $this->db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::PERM_TEMPL_ID . ", ?)");
        foreach ($permissions as $permission) {
            $existing = $this->db->prepare("SELECT id FROM perm_items WHERE name = ?");
            $existing->execute([$permission]);
            $id = $existing->fetchColumn();
            if ($id === false) {
                $stmt->execute([$permission]);
                $id = $this->db->lastInsertId();
            }
            $link->execute([$id]);
        }
    }

    private function zoneMetadataError(): string
    {
        $controller = new class (['id' => (string)self::MISSING_ID], $this->db, $this->primeConfig()) extends EditZoneMetadataController {
            use ControllerTestStubs;
        };
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZone')->willReturn(null);
        (new ReflectionProperty(EditZoneMetadataController::class, 'zoneRepository'))->setValue($controller, $zones);

        return $this->haltMessage(fn() => $controller->run());
    }

    private function deleteRecordError(string $editLevel): string
    {
        $controller = new class (['id' => (string)self::MISSING_ID], $this->db, $this->primeConfig()) extends DeleteRecordController {
            use ControllerTestStubs;
        };
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevel')->willReturn($editLevel);
        $permissions->method('getEditPermissionLevelForZone')->willReturn($editLevel);
        $this->inject($controller, DeleteRecordController::class, ['permissionService' => $permissions, 'userContextService' => new UserContextService()]);

        return $this->haltMessage(fn() => $controller->run());
    }

    private function editRecordError(): string
    {
        $controller = new class (['id' => (string)self::MISSING_ID], $this->db, $this->primeConfig()) extends EditRecordController {
            use ControllerTestStubs;
        };
        $this->inject($controller, EditRecordController::class, ['userContextService' => new UserContextService()]);

        return $this->haltMessage(fn() => $controller->run());
    }

    /**
     * @param class-string $controllerClass
     */
    private function wizardError(string $controllerClass): string
    {
        $request = ['id' => (string)self::MISSING_ID, 'type' => 'spf'];
        $config = $this->primeConfig();
        $controller = $controllerClass === DnsWizardSelectController::class
            ? new class ($request, $this->db, $config) extends DnsWizardSelectController {
                use ControllerTestStubs;
            }
            : new class ($request, $this->db, $config) extends DnsWizardFormController {
                use ControllerTestStubs;
            };

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getDomainNameById')->willReturn(null);
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainType')->willReturn('MASTER');
        $this->inject($controller, $controllerClass, ['zoneRepository' => $zones, 'dnsRecord' => $dnsRecord]);

        return $this->haltMessage(fn() => $controller->run());
    }

    private function importError(): string
    {
        $_SESSION['zone_import_data'] = ['records' => '[]', 'origin' => 'example.com'];
        $_POST = ['import_mode' => 'existing', 'existing_zone_id' => (string)self::MISSING_ID];

        $controller = new class ([], $this->db, $this->primeConfig()) extends ZoneFileImportController {
            use ControllerTestStubs;

            public ?ZoneRepositoryInterface $zones = null;

            protected function createZoneRepository(): ZoneRepositoryInterface
            {
                return $this->zones ?? throw new RuntimeException('No zone repository stub');
            }
        };
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getDomainNameById')->willReturn(null);
        $controller->zones = $zones;
        $this->inject($controller, ZoneFileImportController::class, ['userContextService' => new UserContextService()]);

        return $this->haltMessage(fn() => (new ReflectionMethod(ZoneFileImportController::class, 'handleExecute'))->invoke($controller));
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

    private function primeConfig(): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $reflection->getProperty('settings')->setValue($config, [
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['hostmaster' => 'hostmaster.example', 'ttl' => 3600],
            'modules' => ['dns_wizards' => ['enabled' => true]],
        ]);
        $reflection->getProperty('initialized')->setValue($config, true);

        return $config;
    }
}
