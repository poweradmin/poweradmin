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
 * Every single write ends by bumping the serial; a batch caller (bulk operations,
 * RRSet replace) passes finalizeZone off and calls finalizeZone() once itself.
 */
class RecordManagerFinalizeZoneTest extends SqliteIntegrationTestCase
{
    private const ZONE_ID = 10;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (" . self::ZONE_ID . ", " . self::ADMIN_USER_ID . ")");
    }

    #[RunInSeparateProcess]
    public function testABatchCreateLeavesTheSerialToTheCaller(): void
    {
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->never())->method('updateSOASerial');
        $changeLogger = $this->createMock(RecordChangeLogger::class);
        $changeLogger->expects($this->once())->method('logRecordCreate');

        $result = $this->makeRecordManager($soa, $changeLogger)->addRecordGetId(self::ZONE_ID, 'www.example.com', 'A', '192.0.2.1', 3600, 0, 0, false);

        $this->assertTrue($result->success);
        $this->assertSame(55, $result->recordId);
    }

    #[RunInSeparateProcess]
    public function testASingleCreateBumpsTheSerial(): void
    {
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->once())->method('updateSOASerial')->with(self::ZONE_ID);

        $this->assertTrue($this->makeRecordManager($soa, $this->createMock(RecordChangeLogger::class))->addRecordGetId(self::ZONE_ID, 'www.example.com', 'A', '192.0.2.1', 3600, 0)->success);
    }

    #[RunInSeparateProcess]
    public function testASingleCreateCommitsTheRowAndTheSerialTogether(): void
    {
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->method('updateSOASerial')->willReturnCallback(function (): bool {
            $this->assertTrue($this->db->inTransaction(), 'the serial bump must share the insert transaction');
            return true;
        });

        $this->assertTrue($this->makeRecordManager($soa, $this->createMock(RecordChangeLogger::class))->addRecordGetId(self::ZONE_ID, 'www.example.com', 'A', '192.0.2.1', 3600, 0)->success);
        $this->assertFalse($this->db->inTransaction());
    }

    #[RunInSeparateProcess]
    public function testABackendRefusalRollsTheTransactionBack(): void
    {
        $backend = $this->dnsBackendStub(false);
        $backend->method('addRecordGetId')->willReturn(null);
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->never())->method('updateSOASerial');

        $result = $this->makeRecordManager($soa, $this->createMock(RecordChangeLogger::class), $backend)->addRecordGetId(self::ZONE_ID, 'www.example.com', 'A', '192.0.2.1', 3600, 0);

        $this->assertSame(500, $result->status);
        $this->assertFalse($this->db->inTransaction());
    }

    #[RunInSeparateProcess]
    public function testADuplicateIsRefusedBeforeTheBackendIsAsked(): void
    {
        $this->db->exec("INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES (" . self::ZONE_ID . ", 'www.example.com', 'A', '192.0.2.1', 3600, 0)");
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('addRecordGetId');

        $result = $this->makeRecordManager($this->createMock(SOARecordManagerInterface::class), $this->createMock(RecordChangeLogger::class), $backend)->addRecordGetId(self::ZONE_ID, 'www.example.com', 'A', '192.0.2.1', 3600, 0, 0, false);

        $this->assertFalse($result->success);
        $this->assertSame(409, $result->status);
    }

    #[RunInSeparateProcess]
    public function testAnEditBumpsTheSerialUnlessTheCallerFinalises(): void
    {
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (7, " . self::ZONE_ID . ", 'www.example.com', 'A', '192.0.2.9', 3600, 0)");
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->once())->method('updateSOASerial')->with(self::ZONE_ID);
        $backend = $this->dnsBackendStub(false);
        $backend->method('editRecord')->willReturn(true);
        $manager = $this->makeRecordManager($soa, $this->createMock(RecordChangeLogger::class), $backend);
        $record = ['rid' => 7, 'zid' => self::ZONE_ID, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];

        $this->assertTrue($manager->editRecord($record, false)->success);
        $this->assertTrue($manager->editRecord($record)->success);
    }

    #[RunInSeparateProcess]
    public function testFinalizeZoneBumpsTheSerialUnlessToldTheSoaWasWritten(): void
    {
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->once())->method('updateSOASerial')->with(self::ZONE_ID);
        $manager = $this->makeRecordManager($soa, $this->createMock(RecordChangeLogger::class));

        $manager->finalizeZone(self::ZONE_ID);
        $manager->finalizeZone(self::ZONE_ID, false);
    }

    private function makeRecordManager(SOARecordManagerInterface $soa, RecordChangeLogger $changeLogger, ?DnsBackendProviderInterface $backend = null): RecordManager
    {
        $config = $this->primeConfigurationManager(['dns' => ['hostmaster' => 'hostmaster.example', 'ttl' => 3600]]);

        $validation = $this->createMock(DnsRecordValidationServiceInterface::class);
        $validation->method('validateRecord')->willReturn(ValidationResult::success([
            'content' => '192.0.2.1',
            'name' => 'www.example.com',
            'ttl' => 3600,
            'prio' => 0,
        ]));
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn('MASTER');
        $domainRepository->method('getDomainNameById')->willReturn('example.com');
        if ($backend === null) {
            $backend = $this->dnsBackendStub(false);
            $backend->method('addRecordGetId')->willReturn(55);
        }

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
            null,
            $changeLogger
        );
    }
}
