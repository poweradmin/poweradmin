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

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Service\Backend\DnsBackendProviderFactory;
use Poweradmin\Application\Service\Backend\RepositoryFactory;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Poweradmin\Infrastructure\Session\SessionActor;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;
use TestHelpers\SqliteIntegrationTestCase;
use TestHelpers\ZoneTemplateServiceBuilder;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Infrastructure\Session\PhpSession;

/**
 * API zone creation must apply the same name and template rules as the web UI:
 * names are stored as punycode, dns.third_level_check refuses subzones of an
 * existing zone, and a template is usable when it is global (owner 0), owned
 * by the caller, or the caller is ueberuser.
 */
#[CoversClass(ZoneManagementService::class)]
class ZoneManagementServiceCreateRulesTest extends SqliteIntegrationTestCase
{
    private const OTHER_USER = 2;
    private const PRIVATE_TEMPLATE = 10;
    private const GLOBAL_TEMPLATE = 11;

    private bool $thirdLevelCheck = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->createZoneTables();
        $this->db->exec("INSERT INTO domains (name, type) VALUES ('xn--bcher-kva.example', 'MASTER'), ('parent.example', 'MASTER')");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', owner INTEGER NOT NULL DEFAULT 0, created_by INTEGER)");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (2, 'Client')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::OTHER_USER . ", 'client', 2)");
        $this->db->exec("INSERT INTO zone_templ (id, name, owner) VALUES
            (" . self::PRIVATE_TEMPLATE . ", 'admin-private', " . self::ADMIN_USER_ID . "),
            (" . self::GLOBAL_TEMPLATE . ", 'global', 0),
            (12, 'dup', 0),
            (13, 'dup', 0)");
    }

    private function service(?PdnsCapabilities $capabilities = null, ?DomainRepositoryInterface $domains = null): ZoneManagementService
    {
        $config = new FakeConfiguration([
            'dns' => ['third_level_check' => $this->thirdLevelCheck],
            'database' => ['type' => 'sqlite'],
        ]);

        $backend = DnsBackendProviderFactory::create($this->db, $config);

        return new ZoneManagementService(
            $this->createMock(ZoneRepositoryInterface::class),
            $config,
            new RepositoryFactory($this->db, $config, $backend),
            $this->permissionService($config),
            $this->createMock(RecordChangeWriterInterface::class),
            fn() => (new ControllerServiceFactory($this->db, $config, new NullLogger(), new SessionActor(new PhpSession()), new PhpSession()))->domainManager(),
            $this->zoneTemplateService($config, $backend),
            null,
            $capabilities,
            null,
            $domains
        );
    }

    private function zoneTemplateService(ConfigurationInterface $config, DnsBackendProviderInterface $backend): ZoneTemplateService
    {
        return ZoneTemplateServiceBuilder::build(
            new DbZoneTemplateRepository($this->db, $config, $backend),
            $config,
            $backend,
            $this->permissionService($config),
            new SessionActor(new PhpSession()),
            new NullLogger()
        );
    }

    private function existingZoneOfType(string $type): DomainRepositoryInterface
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('zoneIdExists')->willReturn(true);
        $domains->method('getDomainType')->willReturn($type);

        return $domains;
    }

    public function testRefusalsCarryACodeForTheForms(): void
    {
        $this->assertSame(ZoneManagementService::ERR_EXISTS, $this->service()->createZone('parent.example', 'MASTER', self::ADMIN_USER_ID)['code']);
        $this->assertSame(ZoneManagementService::ERR_INVALID_NAME, $this->service()->createZone('new.example..', 'MASTER', self::ADMIN_USER_ID)['code']);
        $this->assertSame(ZoneManagementService::ERR_NO_OWNER, $this->service()->createZone('new.example', 'MASTER', null)['code']);
    }

    public function testAReplicatingZoneNeedsAValidPrimary(): void
    {
        $missing = $this->service()->createZone('new.example', 'SLAVE', self::ADMIN_USER_ID);
        $this->assertSame(Refusal::INVALID_INPUT, $missing['refusal']);
        $this->assertSame(ZoneManagementService::ERR_MASTER_REQUIRED, $missing['code']);

        $invalid = $this->service()->createZone('new.example', 'SLAVE', self::ADMIN_USER_ID, 'not-an-ip');
        $this->assertSame(Refusal::INVALID_INPUT, $invalid['refusal']);
        $this->assertSame(ZoneManagementService::ERR_INVALID_MASTER, $invalid['code']);
        $this->assertStringContainsString('Invalid master servers format', $invalid['message']);

        // A master list is checked whenever one is given, whatever the kind.
        $this->assertSame(ZoneManagementService::ERR_INVALID_MASTER, $this->service()->createZone('new.example', 'MASTER', self::ADMIN_USER_ID, 'not-an-ip')['code']);
    }

    public function testCatalogKindsNeedAServerThatHasThem(): void
    {
        $tooOld = $this->service(PdnsCapabilities::fromVersion('4.6.0'))->createZone('catalog.example', 'PRODUCER', self::ADMIN_USER_ID);
        $this->assertSame(ZoneManagementService::ERR_INVALID_TYPE, $tooOld['code']);

        // A consumer replicates like a secondary, so it needs a primary too.
        $consumer = $this->service(PdnsCapabilities::fromVersion('4.7.0'))->createZone('catalog.example', 'CONSUMER', self::ADMIN_USER_ID);
        $this->assertSame(ZoneManagementService::ERR_MASTER_REQUIRED, $consumer['code']);
    }

    public function testCapabilitiesAreOnlyLookedUpForACatalogKind(): void
    {
        $lookups = 0;
        $lazy = function () use (&$lookups) {
            $lookups++;
            return PdnsCapabilities::fromVersion('4.7.0');
        };
        $config = new FakeConfiguration(['database' => ['type' => 'sqlite']]);
        $backend = DnsBackendProviderFactory::create($this->db, $config);
        $service = new ZoneManagementService(
            $this->createMock(ZoneRepositoryInterface::class),
            $config,
            new RepositoryFactory($this->db, $config, $backend),
            $this->permissionService($config),
            $this->createMock(RecordChangeWriterInterface::class),
            fn() => (new ControllerServiceFactory($this->db, $config, new NullLogger(), new SessionActor(new PhpSession()), new PhpSession()))->domainManager(),
            $this->zoneTemplateService($config, $backend),
            null,
            $lazy
        );

        $service->createZone('new.example', 'BOGUS', self::ADMIN_USER_ID);
        $this->assertSame(0, $lookups, 'a basic-kind refusal must not fetch the server version');

        $service->createZone('catalog.example', 'PRODUCER', self::ADMIN_USER_ID);
        $service->createZone('catalog2.example', 'PRODUCER', self::ADMIN_USER_ID);
        $this->assertSame(1, $lookups, 'the version is fetched once per service');
    }

    public function testCreateZoneLooksUpTheNameAsPunycode(): void
    {
        $result = $this->service()->createZone('bücher.example', 'MASTER', self::ADMIN_USER_ID);

        $this->assertSame(Refusal::CONFLICT, $result['refusal']);
        $this->assertSame('Domain already exists', $result['message']);
    }

    public function testCreateZoneRejectsDoubledTrailingDot(): void
    {
        $result = $this->service()->createZone('new.example..', 'MASTER', self::ADMIN_USER_ID);

        $this->assertSame(Refusal::INVALID_INPUT, $result['refusal']);
    }

    public function testCreateZoneRefusesSubzoneOfExistingZoneWhenThirdLevelCheckIsOn(): void
    {
        $this->thirdLevelCheck = true;

        foreach (['sub.parent.example', 'sub.parent.example.'] as $name) {
            $result = $this->service()->createZone($name, 'MASTER', self::ADMIN_USER_ID);

            $this->assertSame(Refusal::CONFLICT, $result['refusal'], $name);
            $this->assertSame('Domain already exists', $result['message']);
        }
    }

    public function testApplyTemplateRefusesUnknownAndReadOnlyZones(): void
    {
        $this->assertSame(ZoneManagementService::ERR_NOT_FOUND, $this->service()->applyTemplate(99, 'none', self::ADMIN_USER_ID)['code']);

        $refused = $this->service(null, $this->existingZoneOfType('SLAVE'))->applyTemplate(1, (string)self::GLOBAL_TEMPLATE, self::ADMIN_USER_ID);

        $this->assertSame(ZoneManagementService::ERR_READ_ONLY, $refused['code']);
        $this->assertSame(Refusal::INVALID_INPUT, $refused['refusal']);
    }

    public function testApplyTemplateEnforcesTheTemplateRules(): void
    {
        $service = $this->service(null, $this->existingZoneOfType('MASTER'));

        $this->assertSame(ZoneManagementService::ERR_TEMPLATE_FORBIDDEN, $service->applyTemplate(1, (string)self::PRIVATE_TEMPLATE, self::OTHER_USER)['code']);
        $this->assertSame(ZoneManagementService::ERR_TEMPLATE_NOT_FOUND, $service->applyTemplate(1, '999', self::ADMIN_USER_ID)['code']);
    }

    public function testNoTemplateResolvesToNone(): void
    {
        $this->assertSame(['id' => 'none'], $this->service()->resolveZoneTemplate('none', self::OTHER_USER));
        $this->assertSame(['id' => 'none'], $this->service()->resolveZoneTemplate('', self::OTHER_USER));
    }

    public function testUnknownTemplateIs404(): void
    {
        $this->assertSame(Refusal::NOT_FOUND, $this->service()->resolveZoneTemplate('999', self::ADMIN_USER_ID)['refusal']);
        $this->assertSame(Refusal::NOT_FOUND, $this->service()->resolveZoneTemplate('missing', self::ADMIN_USER_ID)['refusal']);
    }

    public function testAmbiguousNameIs409(): void
    {
        $this->assertSame(Refusal::CONFLICT, $this->service()->resolveZoneTemplate('dup', self::ADMIN_USER_ID)['refusal']);
    }

    public function testOtherUsersPrivateTemplateIsRefused(): void
    {
        $result = $this->service()->resolveZoneTemplate((string)self::PRIVATE_TEMPLATE, self::OTHER_USER);

        $this->assertSame(Refusal::FORBIDDEN, $result['refusal']);
        $this->assertSame('You do not have permission to use this zone template', $result['message']);

        $byName = $this->service()->resolveZoneTemplate('admin-private', self::OTHER_USER);
        $this->assertSame(Refusal::FORBIDDEN, $byName['refusal']);
    }

    public function testGlobalTemplateIsUsableByAnyone(): void
    {
        $this->assertSame(['id' => '11'], $this->service()->resolveZoneTemplate('global', self::OTHER_USER));
    }

    public function testOwnerAndUeberuserMayUsePrivateTemplate(): void
    {
        $this->assertSame(['id' => '10'], $this->service()->resolveZoneTemplate((string)self::PRIVATE_TEMPLATE, self::ADMIN_USER_ID));

        $this->db->exec("UPDATE zone_templ SET owner = " . self::OTHER_USER . " WHERE id = " . self::PRIVATE_TEMPLATE);
        $this->assertSame(['id' => '10'], $this->service()->resolveZoneTemplate((string)self::PRIVATE_TEMPLATE, self::OTHER_USER));
        $this->assertSame(['id' => '10'], $this->service()->resolveZoneTemplate((string)self::PRIVATE_TEMPLATE, self::ADMIN_USER_ID));
    }

    public function testWithoutActingUserOnlyExistenceIsChecked(): void
    {
        $this->assertSame(['id' => '10'], $this->service()->resolveZoneTemplate((string)self::PRIVATE_TEMPLATE, null));
    }
}
