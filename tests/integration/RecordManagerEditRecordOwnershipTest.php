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

namespace Poweradmin\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Application\Service\RepositoryFactory;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\DnssecProviderInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * IDOR guard for RecordManager::editRecord(). The record's zone must be derived
 * from the record id server-side; a caller-supplied zid must never be trusted.
 * Otherwise a "client" who owns one zone can pass that zone's id to satisfy the
 * ownership check while editing a record that lives in a zone it does not own.
 */
class RecordManagerEditRecordOwnershipTest extends SqliteIntegrationTestCase
{
    private const ATTACKER_USER_ID = 100;
    private const ATTACKER_PERM_TEMPL_ID = 100;

    private const VICTIM_ZONE_ID = 2;
    private const ATTACKER_ZONE_ID = 3;
    private const VICTIM_RECORD_ID = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");

        // Victim zone belongs to the admin; the attacker only owns its own zone.
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, " . self::VICTIM_ZONE_ID . ", " . self::ADMIN_USER_ID . ")");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (2, " . self::ATTACKER_ZONE_ID . ", " . self::ATTACKER_USER_ID . ")");

        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (" . self::VICTIM_RECORD_ID . ", " . self::VICTIM_ZONE_ID . ", 'www.victim.example', 'A', '1.1.1.1', 3600, 0, 0)");

        $this->seedAttackerAsClient();
    }

    #[RunInSeparateProcess]
    public function testForgedZoneIdCannotEditForeignRecord(): void
    {
        // rid points at the victim's record, zid at the attacker's own zone.
        $this->assertForgedEditRejected(
            self::VICTIM_RECORD_ID,
            'pwned.attacker.example',
            403,
            'editRecord must reject a record whose real zone the caller does not own, even when the request carries an owned zid.'
        );
    }

    #[RunInSeparateProcess]
    public function testEditRejectsUnknownRecordId(): void
    {
        $this->assertForgedEditRejected(9999, 'ghost.attacker.example', 404);
    }

    #[RunInSeparateProcess]
    public function testDeleteOfForeignRecordIsForbidden(): void
    {
        $_SESSION['userid'] = self::ATTACKER_USER_ID;
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('deleteRecord');

        $result = $this->makeRecordManager($backend)->deleteRecord(self::VICTIM_RECORD_ID);

        $this->assertFalse($result->success);
        $this->assertSame(403, $result->status);
    }

    #[RunInSeparateProcess]
    public function testDeleteOfUnknownRecordIsNotFound(): void
    {
        $_SESSION['userid'] = self::ATTACKER_USER_ID;
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('deleteRecord');

        $result = $this->makeRecordManager($backend)->deleteRecord(9999);

        $this->assertFalse($result->success);
        $this->assertSame(404, $result->status);
    }

    /**
     * Drive editRecord() as the attacker with a request that always claims the
     * attacker's own zone id, and assert the write never reaches the backend.
     */
    private function assertForgedEditRejected(int $rid, string $name, int $expectedStatus, string $message = ''): void
    {
        $_SESSION['userid'] = self::ATTACKER_USER_ID;

        $record = [
            'rid' => $rid,
            'zid' => self::ATTACKER_ZONE_ID,
            'name' => $name,
            'type' => 'A',
            'content' => '6.6.6.6',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ];

        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('editRecord');

        $manager = $this->makeRecordManager($backend);

        $result = $manager->editRecord($record);
        $this->assertFalse($result->success, $message);
        $this->assertSame($expectedStatus, $result->status, $message);
    }

    private function seedAttackerAsClient(): void
    {
        // zone_content_edit_own_as_client is what makes the pre-fix bug exploitable:
        // with it the attacker passes the ownership check on its own zid and writes.
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (60, '" . Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT . "')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::ATTACKER_PERM_TEMPL_ID . ", 'Client')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::ATTACKER_PERM_TEMPL_ID . ", 60)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::ATTACKER_USER_ID . ", 'attacker', " . self::ATTACKER_PERM_TEMPL_ID . ")");
    }

    private function makeRecordManager(DnsBackendProviderInterface $backend): RecordManager
    {
        $config = $this->primeConfigurationManager(['dns' => ['hostmaster' => 'hostmaster.example', 'ttl' => 3600]]);

        // The validation and zone-name lookups are stubbed to succeed so that, on
        // vulnerable code, nothing but the ownership check stands between the forged
        // request and backend->editRecord() - the write we assert never happens.
        $validation = $this->createMock(DnsRecordValidationServiceInterface::class);
        $validation->method('validateRecord')->willReturn(ValidationResult::success([
            'content' => '6.6.6.6',
            'name' => 'pwned.attacker.example',
            'ttl' => 3600,
            'prio' => 0,
        ]));
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn('MASTER');
        $domainRepository->method('getDomainNameById')->willReturn('attacker.example');
        $changeLogger = $this->createMock(RecordChangeLogger::class);

        return new RecordManager(
            $this->db,
            $config,
            $validation,
            $soa,
            $domainRepository,
            new RepositoryFactory($this->db, $config, $backend),
            fn() => $this->createMock(DnssecProviderInterface::class),
            $backend,
            $this->permissionService($config),
            $changeLogger
        );
    }
}
