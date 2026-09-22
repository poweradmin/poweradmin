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
use Poweradmin\Infrastructure\Session\PhpSession;

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
            new PhpSession()
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
}
