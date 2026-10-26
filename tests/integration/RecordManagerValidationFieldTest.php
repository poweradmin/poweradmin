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
use Poweradmin\Application\Service\Backend\RepositoryFactory;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Validation\RecordField;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use TestHelpers\SqliteIntegrationTestCase;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use Poweradmin\Infrastructure\Session\SessionActor;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * A refused validation reaches the caller with the field the validator named,
 * so the forms no longer have to guess it from the message. An edit into a copy
 * of another record is refused with the duplicate field, as an add is.
 */
class RecordManagerValidationFieldTest extends SqliteIntegrationTestCase
{
    private const ZONE_ID = 2;
    private const RECORD_ID = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0, ordername TEXT, auth INTEGER DEFAULT 1)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, " . self::ZONE_ID . ", " . self::ADMIN_USER_ID . ")");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (" . self::RECORD_ID . ", " . self::ZONE_ID . ", 'www.example.test', 'A', '192.0.2.1', 3600, 0, 0)");
    }

    #[RunInSeparateProcess]
    public function testAddCarriesTheFieldTheValidatorNamed(): void
    {
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('addRecord');

        $result = $this->makeRecordManager($backend)->addRecordGetId(self::ZONE_ID, 'www', 'A', '192.0.2.2', -1, 0);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::INVALID_INPUT, $result->refusal);
        $this->assertSame('TTL value cannot be negative. It must be 0 or higher.', $result->message);
        $this->assertSame(RecordField::TTL, $result->field);
    }

    #[RunInSeparateProcess]
    public function testEditCarriesTheFieldTheValidatorNamed(): void
    {
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('editRecord');

        $result = $this->makeRecordManager($backend)->editRecord([
            'rid' => self::RECORD_ID,
            'zid' => self::ZONE_ID,
            'name' => 'www.example.test',
            'type' => 'A',
            'content' => '192.0.2.2',
            'ttl' => -1,
            'prio' => 0,
            'disabled' => 0,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::INVALID_INPUT, $result->refusal);
        $this->assertSame(RecordField::TTL, $result->field);
    }

    #[RunInSeparateProcess]
    public function testEditIntoACopyOfAnotherRecordCarriesTheDuplicateField(): void
    {
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (6, " . self::ZONE_ID . ", 'www.example.test', 'A', '192.0.2.2', 3600, 0, 0)");
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('editRecord');

        $result = $this->makeRecordManager($backend, $this->passingValidation())->editRecord($this->editOf('WWW.example.test', '192.0.2.2'));

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::CONFLICT, $result->refusal);
        $this->assertSame(RecordField::DUPLICATE, $result->field);
    }

    #[RunInSeparateProcess]
    public function testAnUnchangedEditBesideOtherRecordsIsWritten(): void
    {
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (6, " . self::ZONE_ID . ", 'www.example.test', 'A', '192.0.2.2', 3600, 0, 0)");
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->once())->method('editRecord')->willReturn(true);

        $result = $this->makeRecordManager($backend, $this->passingValidation())->editRecord($this->editOf('www', '192.0.2.1'));

        $this->assertTrue($result->success);
    }

    /**
     * @return array<string, mixed>
     */
    private function editOf(string $name, string $content): array
    {
        return ['rid' => self::RECORD_ID, 'zid' => self::ZONE_ID, 'name' => $name, 'type' => 'A', 'content' => $content, 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];
    }

    private function passingValidation(): DnsRecordValidationServiceInterface
    {
        $validation = $this->createMock(DnsRecordValidationServiceInterface::class);
        $validation->method('validateRecord')->willReturnCallback(
            static fn(int|string $rid, int $zid, string $type, string $content, string $name, ?int $prio, ?int $ttl): ValidationResult => ValidationResult::success(['content' => $content, 'name' => $name, 'ttl' => $ttl, 'prio' => $prio])
        );

        return $validation;
    }

    private function makeRecordManager(DnsBackendProviderInterface $backend, ?DnsRecordValidationServiceInterface $validation = null): RecordManager
    {
        $config = $this->sqliteConfiguration(['dns' => ['hostmaster' => 'hostmaster.example', 'ttl' => 3600]]);

        if ($validation === null) {
            $validation = $this->createMock(DnsRecordValidationServiceInterface::class);
            $validation->method('validateRecord')->willReturn(
                ValidationResult::failure('TTL value cannot be negative. It must be 0 or higher.')->withField(RecordField::TTL)
            );
        }
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn('MASTER');
        $domainRepository->method('getDomainNameById')->willReturn('example.test');

        return new RecordManager(
            new PdoTransaction($this->db),
            $config,
            $validation,
            $this->createMock(SOARecordManagerInterface::class),
            $domainRepository,
            new RepositoryFactory($this->db, $config, $backend, new ArraySession()),
            fn() => $this->createMock(DnssecProviderInterface::class),
            $backend,
            $this->permissionService($config),
            $this->createMock(RecordChangeLogger::class),
            new DbTemplateRecordLinkRepository($this->db, $config, $backend),
            new SessionActor($this->session)
        );
    }
}
