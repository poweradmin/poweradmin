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
use Poweradmin\Application\Controller\BatchPtrRecordController;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * The batch PTR page checks the user's access to a zone before it looks the zone up,
 * so a missing id, a foreign reverse zone and a foreign forward zone all answer alike.
 *
 * UserManager::verifyPermission() caches the permission set in a static, so every
 * test runs in its own process.
 */
class BatchPtrRecordControllerAccessTest extends SqliteIntegrationTestCase
{
    private const USER_ID = 100;
    private const PERM_TEMPL_ID = 100;
    private const MISSING_ZONE_ID = 404;
    private const REVERSE_ZONE_ID = 12;
    private const FORWARD_ZONE_ID = 13;
    private const REFUSAL = 'You do not have the permission to add records to this zone.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::PERM_TEMPL_ID . ", 'Tester')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ID . ", 'tester', " . self::PERM_TEMPL_ID . ")");

        $_SESSION['userid'] = self::USER_ID;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    #[RunInSeparateProcess]
    public function testAMissingZoneGetsThePermissionRefusal(): void
    {
        $this->grant('zone_content_edit_own');

        $this->assertSame(self::REFUSAL, $this->errorFor(self::MISSING_ZONE_ID));
    }

    #[RunInSeparateProcess]
    public function testAForeignReverseZoneGetsThePermissionRefusalNotItsKind(): void
    {
        $this->grant('zone_content_edit_own');

        $this->assertSame(self::REFUSAL, $this->errorFor(self::REVERSE_ZONE_ID));
    }

    #[RunInSeparateProcess]
    public function testAnAllEditorStillLearnsThatAnIdIsMissing(): void
    {
        $this->grant('zone_content_edit_others');

        $this->assertSame('There is no zone with this ID.', $this->errorFor(self::MISSING_ZONE_ID));
    }

    #[RunInSeparateProcess]
    public function testAnOwnerIsStillToldThatReverseZonesAreNotSupported(): void
    {
        $this->grant('zone_content_edit_own');
        $this->own(self::REVERSE_ZONE_ID);

        $this->assertSame('Batch PTR record creation is not available for reverse zones.', $this->errorFor(self::REVERSE_ZONE_ID));
    }

    #[RunInSeparateProcess]
    public function testAnOwnerIsStillRefusedOnASlaveZone(): void
    {
        $this->grant('zone_content_edit_own');
        $this->own(self::FORWARD_ZONE_ID);

        $this->assertSame(self::REFUSAL, $this->errorFor(self::FORWARD_ZONE_ID));
    }

    private function grant(string $permission): void
    {
        $this->db->prepare("INSERT INTO perm_items (name) VALUES (?)")->execute([$permission]);
        $this->db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (?, ?)")
            ->execute([self::PERM_TEMPL_ID, $this->db->lastInsertId()]);
    }

    private function own(int $zoneId): void
    {
        $this->db->prepare("INSERT INTO zones (domain_id, owner) VALUES (?, ?)")->execute([$zoneId, self::USER_ID]);
    }

    private function errorFor(int $zoneId): string
    {
        $_GET = ['id' => (string)$zoneId];

        $controller = new class ([], $this->db, $this->primeConfig()) extends BatchPtrRecordController {
            use ControllerTestStubs;
        };

        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainNameById')->willReturnCallback(static fn(int $id): ?string => match ($id) {
            self::REVERSE_ZONE_ID => '2.0.192.in-addr.arpa',
            self::FORWARD_ZONE_ID => 'example.com',
            default => null,
        });
        $dnsRecord->method('getDomainType')->willReturn('SLAVE');
        foreach (['dnsRecord' => $dnsRecord, 'userContextService' => new UserContextService()] as $name => $value) {
            (new ReflectionProperty(BatchPtrRecordController::class, $name))->setValue($controller, $value);
        }

        try {
            $controller->run();
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
        ]);
        $reflection->getProperty('initialized')->setValue($config, true);

        return $config;
    }
}
