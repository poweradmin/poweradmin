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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Application\Service\User\PasswordPolicyService;
use Poweradmin\Application\Service\Backend\RepositoryFactory;
use Poweradmin\Application\Service\Auth\UserAuthenticationService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\ZoneTemplateApplier;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\User\UserProfileAssembler;
use Poweradmin\Domain\Service\Dns\DomainParsingService;
use Poweradmin\Domain\Service\Template\ZoneTemplatePlaceholders;
use Poweradmin\Infrastructure\Network\PdpPublicSuffixList;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateSyncRepository;
use TestHelpers\SqliteIntegrationTestCase;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use Poweradmin\Infrastructure\Repository\DbZoneAccountOwnerRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Poweradmin\Infrastructure\Session\SessionActor;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Domain\Service\Zone\ZoneAccountSyncService;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * Deleting a user through the web decides zone by zone: refuse the last super
 * admin, refuse before touching anything when a decision is not allowed, and
 * leave no rows keyed on the deleted user behind.
 */
#[CoversClass(UserManagementService::class)]
class UserManagementServiceDeleteDecisionsTest extends SqliteIntegrationTestCase
{
    private ?ZoneManagementService $zoneService = null;

    private const SECOND_ADMIN = 3;
    private const USER_ADMIN = 4;
    private const TARGET = 5;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (
            [
                "CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_preferences (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE user_mfa (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, user_id INTEGER)",
                "CREATE TABLE api_keys (id INTEGER PRIMARY KEY, name TEXT, created_by INTEGER)",
                "CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, owner INTEGER)",
                "CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        $this->createZoneTables();
        $this->createDomainsTable();
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (10, 'reassign.example', 'NATIVE')");
    }

    #[RunInSeparateProcess]
    public function testTheLastSuperAdminCannotBeDeleted(): void
    {
        $result = $this->service()->deleteUserWithZoneDecisions(self::ADMIN_USER_ID, self::ADMIN_USER_ID, []);

        $this->assertFalse($result['success']);
        $this->assertSame(UserManagementService::ERR_LAST_ADMIN, $result['code']);
        $this->assertSame(1, $this->rows('users WHERE id = ' . self::ADMIN_USER_ID));
    }

    #[RunInSeparateProcess]
    public function testASuperAdminGoesWhileAnotherRemains(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::SECOND_ADMIN . ", 'second', " . self::ADMIN_PERM_TEMPL_ID . ")");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (" . self::SECOND_ADMIN . ", 9)");
        $this->db->exec("INSERT INTO zone_templ VALUES (50, 'private', " . self::SECOND_ADMIN . ")");

        $result = $this->service()->deleteUserWithZoneDecisions(self::ADMIN_USER_ID, self::SECOND_ADMIN, []);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->rows('users WHERE id = ' . self::SECOND_ADMIN));
        $this->assertSame(0, $this->rows('user_group_members WHERE user_id = ' . self::SECOND_ADMIN));
        $this->assertSame(0, $this->rows('zone_templ WHERE owner = ' . self::SECOND_ADMIN));
        $this->assertSame(1, $this->rows('users WHERE id = ' . self::ADMIN_USER_ID));
    }

    #[RunInSeparateProcess]
    public function testARefusedZoneDeletionKeepsTheUserAndTouchesNoZone(): void
    {
        // The acting user may delete users and their own zones, but not zones owned by others.
        $this->seedUserAdmin([50 => Permission::PERM_ZONE_DELETE_OWN]);
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (10, " . self::USER_ADMIN . "), (11, " . self::TARGET . ")");

        $decisions = [['zid' => 10, 'target' => 'delete'], ['zid' => 11, 'target' => 'delete']];
        $result = $this->service()->deleteUserWithZoneDecisions(self::USER_ADMIN, self::TARGET, $decisions);

        $this->assertFalse($result['success']);
        $this->assertSame(UserManagementService::ERR_ZONE_DELETE_FORBIDDEN, $result['code']);
        $this->assertSame(1, $this->rows('users WHERE id = ' . self::TARGET));
        $this->assertSame(2, $this->rows('zones'));
    }

    #[RunInSeparateProcess]
    public function testAllowedDeletionsGoThroughTheZoneServiceBeforeTheUserGoes(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::TARGET . ", 'target', " . self::ADMIN_PERM_TEMPL_ID . ")");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (10, " . self::TARGET . ")");
        $this->zoneService = $this->createMock(ZoneManagementService::class);
        $this->zoneService->expects($this->once())->method('deleteZone')->with(10)->willReturn(['success' => true, 'message' => 'Zone deleted successfully']);

        $result = $this->service()->deleteUserWithZoneDecisions(self::ADMIN_USER_ID, self::TARGET, [['zid' => 10, 'target' => 'delete']]);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->rows('users WHERE id = ' . self::TARGET));
    }

    #[RunInSeparateProcess]
    public function testAFailedZoneDeletionKeepsTheUser(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::TARGET . ", 'target', " . self::ADMIN_PERM_TEMPL_ID . ")");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (10, " . self::TARGET . ")");
        $this->zoneService = $this->createMock(ZoneManagementService::class);
        $this->zoneService->method('deleteZone')->willReturn(['success' => false, 'message' => 'Failed to delete zone', 'refusal' => Refusal::BACKEND_FAILURE, 'code' => ZoneManagementService::ERR_ZONE_WRITE]);

        $result = $this->service()->deleteUserWithZoneDecisions(self::ADMIN_USER_ID, self::TARGET, [['zid' => 10, 'target' => 'delete']]);

        $this->assertFalse($result['success']);
        $this->assertSame(UserManagementService::ERR_ZONE_WRITE, $result['code']);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result['refusal']);
        $this->assertSame(1, $this->rows('users WHERE id = ' . self::TARGET));
    }

    #[RunInSeparateProcess]
    public function testAReassignmentWithoutTheMetaGrantKeepsTheUser(): void
    {
        $this->seedUserAdmin([]);
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (10, " . self::TARGET . ")");

        $decisions = [['zid' => 10, 'target' => 'new_owner', 'newowner' => self::USER_ADMIN]];
        $result = $this->service()->deleteUserWithZoneDecisions(self::USER_ADMIN, self::TARGET, $decisions);

        $this->assertFalse($result['success']);
        $this->assertSame(UserManagementService::ERR_ZONE_META_FORBIDDEN, $result['code']);
        $this->assertSame(1, $this->rows('users WHERE id = ' . self::TARGET));
        $this->assertSame(0, $this->rows('zones WHERE owner = ' . self::USER_ADMIN));
    }

    #[RunInSeparateProcess]
    public function testReassignedZonesGetTheNewOwnerBeforeTheUserGoes(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::TARGET . ", 'target', " . self::ADMIN_PERM_TEMPL_ID . ")");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (10, " . self::TARGET . ")");

        $decisions = [['zid' => 10, 'target' => 'new_owner', 'newowner' => self::ADMIN_USER_ID]];
        $result = $this->service()->deleteUserWithZoneDecisions(self::ADMIN_USER_ID, self::TARGET, $decisions);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['zones_affected']);
        $this->assertSame(0, $this->rows('users WHERE id = ' . self::TARGET));
        $this->assertSame(1, $this->rows('zones WHERE domain_id = 10 AND owner = ' . self::ADMIN_USER_ID));
    }

    #[RunInSeparateProcess]
    public function testAZoneHandedToANewOwnerInApiModeKeepsItsRowAndId(): void
    {
        $this->createApiModeTables();
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::TARGET . ", 'target', " . self::ADMIN_PERM_TEMPL_ID . "), (6, 'other', " . self::ADMIN_PERM_TEMPL_ID . ")");
        // b.example is the target's; the new owner already holds an unnamed row on it; c.example is someone else's
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES
            (12, 12, " . self::TARGET . ", 'b.example'), (41, 12, " . self::ADMIN_USER_ID . ", NULL), (20, 20, 6, 'c.example')");

        $decisions = [['zid' => 12, 'target' => 'new_owner', 'newowner' => self::ADMIN_USER_ID]];
        $result = $this->service(true)->deleteUserWithZoneDecisions(self::ADMIN_USER_ID, self::TARGET, $decisions);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->rows('users WHERE id = ' . self::TARGET));
        $this->assertSame(1, $this->rows("zones WHERE id = 12 AND domain_id = 12 AND zone_name = 'b.example' AND owner = " . self::ADMIN_USER_ID));
        $this->assertSame(0, $this->rows('zones WHERE id = 41'));
        $this->assertSame(1, $this->rows("zones WHERE id = 20 AND owner = 6"));
    }

    #[RunInSeparateProcess]
    public function testAHandoverOnASharedIdMovesTheZoneTheIdOpensWhenItIsTheUsers(): void
    {
        $this->createApiModeTables();
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::TARGET . ", 'target', " . self::ADMIN_PERM_TEMPL_ID . "), (6, 'other', " . self::ADMIN_PERM_TEMPL_ID . ")");
        // Id 70 opens q.example (the target's); p.example of another user shares the id
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (70, NULL, 6, 'p.example'), (71, 70, " . self::TARGET . ", 'q.example')");

        $decisions = [['zid' => 70, 'target' => 'new_owner', 'newowner' => self::ADMIN_USER_ID]];
        $result = $this->service(true)->deleteUserWithZoneDecisions(self::ADMIN_USER_ID, self::TARGET, $decisions);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->rows('zones WHERE id = 71 AND owner = ' . self::ADMIN_USER_ID));
        $this->assertSame(1, $this->rows('zones WHERE id = 70 AND owner = 6'));
    }

    #[RunInSeparateProcess]
    public function testAHandoverOnASharedIdOfAZoneItDoesNotOpenIsRefused(): void
    {
        $this->createApiModeTables();
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::TARGET . ", 'target', " . self::ADMIN_PERM_TEMPL_ID . "), (6, 'other', " . self::ADMIN_PERM_TEMPL_ID . ")");
        // Id 70 opens q.example (user 6's); p.example of the target shares the id
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (70, NULL, " . self::TARGET . ", 'p.example'), (71, 70, 6, 'q.example')");

        $decisions = [['zid' => 70, 'target' => 'new_owner', 'newowner' => self::ADMIN_USER_ID]];
        $result = $this->service(true)->deleteUserWithZoneDecisions(self::ADMIN_USER_ID, self::TARGET, $decisions);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $this->rows('users WHERE id = ' . self::TARGET));
        $this->assertSame(0, $this->rows('zones WHERE domain_id = 70 AND owner = ' . self::ADMIN_USER_ID));
        $this->assertSame(1, $this->rows('zones WHERE id = 71 AND owner = 6'));
    }

    #[RunInSeparateProcess]
    public function testAZoneHandedToTheUserBeingDeletedIsRefusedBeforeAnythingChanges(): void
    {
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::TARGET . ", 'target', " . self::ADMIN_PERM_TEMPL_ID . ")");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (10, " . self::TARGET . "), (11, " . self::TARGET . "), (12, " . self::TARGET . ")");
        $this->zoneService = $this->createMock(ZoneManagementService::class);
        $this->zoneService->expects($this->never())->method('deleteZone');

        // A valid handover on a lower zone id comes first, so a late refusal would leave it applied
        $decisions = [
            ['zid' => 10, 'target' => 'new_owner', 'newowner' => self::ADMIN_USER_ID],
            ['zid' => 11, 'target' => 'new_owner', 'newowner' => self::TARGET],
            ['zid' => 12, 'target' => 'delete'],
        ];
        $result = $this->service()->deleteUserWithZoneDecisions(self::ADMIN_USER_ID, self::TARGET, $decisions);

        $this->assertFalse($result['success']);
        $this->assertSame(UserManagementService::ERR_TRANSFER_TARGET, $result['code']);
        $this->assertSame(1, $this->rows('users WHERE id = ' . self::TARGET));
        $this->assertSame(3, $this->rows('zones WHERE domain_id IN (10, 11, 12) AND owner = ' . self::TARGET));
        $this->assertSame(0, $this->rows('zones WHERE owner = ' . self::ADMIN_USER_ID));
    }

    #[RunInSeparateProcess]
    public function testAZoneHandedToTheUserBeingDeletedInApiModeIsRefusedAndKeepsItsRow(): void
    {
        $this->createApiModeTables();
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::TARGET . ", 'target', " . self::ADMIN_PERM_TEMPL_ID . ")");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES
            (12, 12, " . self::TARGET . ", 'b.example'), (13, 13, " . self::TARGET . ", 'c.example'), (14, 14, " . self::TARGET . ", 'd.example')");
        $this->zoneService = $this->createMock(ZoneManagementService::class);
        $this->zoneService->expects($this->never())->method('deleteZone');

        $decisions = [
            ['zid' => 12, 'target' => 'new_owner', 'newowner' => self::ADMIN_USER_ID],
            ['zid' => 13, 'target' => 'new_owner', 'newowner' => self::TARGET],
            ['zid' => 14, 'target' => 'delete'],
        ];
        $result = $this->service(true)->deleteUserWithZoneDecisions(self::ADMIN_USER_ID, self::TARGET, $decisions);

        $this->assertFalse($result['success']);
        $this->assertSame(UserManagementService::ERR_TRANSFER_TARGET, $result['code']);
        $this->assertSame(1, $this->rows('users WHERE id = ' . self::TARGET));
        $this->assertSame(3, $this->rows("zones WHERE id IN (12, 13, 14) AND zone_name IS NOT NULL AND owner = " . self::TARGET));
        $this->assertSame(0, $this->rows('zones WHERE owner = ' . self::ADMIN_USER_ID));
    }

    private function createApiModeTables(): void
    {
        $this->db->exec("CREATE TABLE app_settings (setting_key TEXT NOT NULL PRIMARY KEY, setting_value TEXT NOT NULL, value_type TEXT NOT NULL DEFAULT 'string')");
        $this->db->exec("ALTER TABLE zones ADD COLUMN comment TEXT");
    }

    /**
     * @param array<int, string> $extraPermissions perm_items id => name granted on top of user_edit_others
     */
    private function seedUserAdmin(array $extraPermissions): void
    {
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (44, '" . Permission::PERM_USER_EDIT_OTHERS . "')");
        foreach ($extraPermissions as $id => $name) {
            $this->db->exec("INSERT INTO perm_items (id, name) VALUES ($id, '$name')");
        }
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (20, 'User admin')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (20, 44)");
        foreach (array_keys($extraPermissions) as $id) {
            $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (20, $id)");
        }
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ADMIN . ", 'useradmin', 20), (" . self::TARGET . ", 'target', 20)");
    }

    private function service(bool $apiBackend = false): UserManagementService
    {
        // Refusal tests never reach the zone service; a bare mock fails loudly if they do
        $this->zoneService ??= $this->createMock(ZoneManagementService::class);
        $config = $this->sqliteConfiguration();
        $userRepository = new DbUserRepository($this->db, $config, $apiBackend);
        $permissions = new PermissionService($userRepository);

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainNameById')->willReturn('zone.example');
        $backend = $this->dnsBackendStub($apiBackend);
        $backend->method('deleteZone')->willReturn(true);
        $domainManager = new DomainManager(
            new PdoTransaction($this->db),
            $config,
            $domainRepository,
            new RepositoryFactory($this->db, $config, $backend, new ArraySession()),
            $backend,
            $this->permissionService($config, $backend->allocatesZoneIdsLocally()),
            new DbUserRepository($this->db, $config, $backend->allocatesZoneIdsLocally()),
            $this->createMock(RecordChangeWriterInterface::class),
            $this->createMock(ZoneTemplateApplier::class),
            new DbZoneTemplateRepository($this->db, $config, $backend),
            new ZoneTemplatePlaceholders($config, new DomainParsingService(new PdpPublicSuffixList())),
            new DbZoneTemplateSyncRepository($this->db, $config),
            new DbTemplateRecordLinkRepository($this->db, $config, $backend),
            new DbZoneGroupRepository($this->db, $config, $backend->isApiBackend()),
            new ZoneAccountSyncService(new DbZoneAccountOwnerRepository($this->db, $backend->allocatesZoneIdsLocally()), $config, $backend),
            new SessionActor($this->session)
        );

        return new UserManagementService(
            $userRepository,
            $permissions,
            new UserProfileAssembler($permissions, $this->createMock(UserGroupRepositoryInterface::class)),
            new UserAuthenticationService(),
            new PasswordPolicyService($config),
            false,
            $domainManager,
            $this->zoneService
        );
    }

    private function rows(string $fromWhere): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $fromWhere")->fetchColumn();
    }
}
