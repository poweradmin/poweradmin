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

namespace Poweradmin\Tests\Unit\Application\Service\Factory;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\ApiKeyActor;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use ReflectionProperty;
use TestHelpers\FakeConfiguration;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * The DNS managers are wired from the request's one object graph: the record
 * manager reads and writes through the same repository factory, SOA manager,
 * validation service and change log that every other service on the factory uses.
 */
class RecordServicesTest extends TestCase
{
    private function factory(): ControllerServiceFactory
    {
        return new ControllerServiceFactory(
            $this->createMock(PDO::class),
            new FakeConfiguration(['database' => ['type' => 'mysql'], 'dns' => ['backend' => 'sql']]),
            new \Psr\Log\NullLogger(),
            new ApiKeyActor(7, 'alice'),
            new ArraySession()
        );
    }

    private static function property(object $object, string $name): mixed
    {
        $property = new ReflectionProperty($object, $name);
        return $property->getValue($object);
    }

    public function testManagersImplementTheirPorts(): void
    {
        $factory = $this->factory();

        $this->assertInstanceOf(SOARecordManagerInterface::class, $factory->soaRecordManager());
        $this->assertInstanceOf(RecordManagerInterface::class, $factory->recordManager());
        $this->assertInstanceOf(DomainManagerInterface::class, $factory->domainManager());
        $this->assertInstanceOf(SupermasterManager::class, $factory->supermasterManager());
        $this->assertInstanceOf(DnsRecordValidationServiceInterface::class, $factory->records()->dnsRecordValidationService());
    }

    public function testRecordManagerSharesTheRequestGraph(): void
    {
        $factory = $this->factory();
        $manager = $factory->recordManager();

        $this->assertSame($factory->backend()->repositoryFactory(), self::property($manager, 'repositoryFactory'));
        $this->assertSame($factory->domainRepository(), self::property($manager, 'domainRepository'));
        $this->assertSame($factory->soaRecordManager(), self::property($manager, 'soaRecordManager'));
        $this->assertSame($factory->dnsBackendProvider(), self::property($manager, 'backendProvider'));
        $this->assertSame($factory->permissionService(), self::property($manager, 'permissionService'));
        $this->assertSame($factory->recordChangeLog(), self::property($manager, 'changeLogger'));
        $this->assertSame($factory->records()->dnsRecordValidationService(), self::property($manager, 'validationService'));
    }

    public function testValidationServiceIsBuiltOncePerRequest(): void
    {
        $factory = $this->factory();

        $this->assertSame($factory->records()->dnsRecordValidationService(), $factory->records()->dnsRecordValidationService());
    }

    public function testRecordManagerServiceReadsThroughTheSharedRecordRepository(): void
    {
        $factory = $this->factory();

        $this->assertSame($factory->recordRepository(), self::property($factory->recordManagerService(), 'recordRepository'));
    }

    public function testDeletionSnapshotKeepsDisabledRecordsAsMarkedLines(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER, ordername TEXT, auth INTEGER)');
        $db->exec("INSERT INTO domains (id, name) VALUES (5, 'example.com')");
        $db->exec("INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, auth) VALUES
            (5, 'www.example.com', 'A', '192.0.2.1', 3600, 0, 0, 1),
            (5, 'off.example.com', 'A', '192.0.2.2', 300, 0, 1, 1),
            (6, 'off.example.com', 'A', '192.0.2.3', 300, 0, 1, 1)");
        $factory = new ControllerServiceFactory(
            $db,
            new FakeConfiguration(['database' => ['type' => 'sqlite'], 'dns' => ['backend' => 'sql']]),
            new \Psr\Log\NullLogger(),
            new ApiKeyActor(7, 'alice'),
            new ArraySession()
        );
        $snapshot = self::property($factory->records()->zoneChangeRequestService(), 'zoneSnapshot');

        $zoneFile = $snapshot(5, 'example.com');

        $this->assertStringContainsString("www.example.com. 3600 IN A 192.0.2.1\n", $zoneFile);
        $this->assertStringContainsString("; poweradmin-disabled: off.example.com. 300 IN A 192.0.2.2\n", $zoneFile);
        $this->assertStringNotContainsString('192.0.2.3', $zoneFile);

        $db->exec('UPDATE records SET disabled = 1');
        $this->assertStringContainsString('; poweradmin-disabled: www.example.com. 3600 IN A 192.0.2.1', $snapshot(5, 'example.com'));
        $this->assertNull($snapshot(9, 'empty.example.com'));
    }

    public function testDeletionSnapshotFallsBackToEnabledRecordsWhenTheMarkersDoNotFit(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER, ordername TEXT, auth INTEGER)');
        $db->exec("INSERT INTO domains (id, name) VALUES (5, 'example.com')");
        $db->exec("INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, auth) VALUES (5, 'www.example.com', 'A', '192.0.2.1', 3600, 0, 0, 1)");
        $insert = $db->prepare("INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, auth) VALUES (5, ?, 'TXT', ?, 3600, 0, 1, 1)");
        for ($i = 0; $i < 300; $i++) {
            $insert->execute(["off$i.example.com", '"' . str_repeat('x', 200) . '"']);
        }
        $factory = new ControllerServiceFactory(
            $db,
            new FakeConfiguration(['database' => ['type' => 'sqlite'], 'dns' => ['backend' => 'sql']]),
            new \Psr\Log\NullLogger(),
            new ApiKeyActor(7, 'alice'),
            new ArraySession()
        );
        $snapshot = self::property($factory->records()->zoneChangeRequestService(), 'zoneSnapshot');

        $zoneFile = $snapshot(5, 'example.com');

        $this->assertStringContainsString("www.example.com. 3600 IN A 192.0.2.1\n", $zoneFile);
        $this->assertStringNotContainsString('poweradmin-disabled', $zoneFile);
    }
}
