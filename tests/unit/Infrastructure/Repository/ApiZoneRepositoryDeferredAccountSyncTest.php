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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Infrastructure\Database\DeadlockRetry;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;
use TestHelpers\FakeConfiguration;

/**
 * PowerDNS is told about an owner change only after the transaction that holds the
 * ownership locks has committed, once, and never for a write that rolled back.
 */
#[CoversClass(ApiZoneRepository::class)]
#[CoversClass(PdoTransaction::class)]
class ApiZoneRepositoryDeferredAccountSyncTest extends TestCase
{
    private PDO $db;
    private PdoTransaction $transaction;
    private ApiZoneRepository $repository;
    private FakeConfiguration $config;

    /** @var list<array{int, string, bool}> Zone id, account and whether a transaction was open */
    private array $pushes = [];
    private bool $failPush = false;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT,
            zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, value_type TEXT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (1, 'primary', 'P'), (2, 'extra', 'E'), (3, 'newcomer', 'N')");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, comment, owner, zone_templ_id) VALUES
            (4, 2905, 'shared.example.com', 'MASTER', '', '', 1, 0),
            (5, 2905, NULL, NULL, NULL, NULL, 2, 0)");

        $provider = $this->createMock(DnsBackendProviderInterface::class);
        $provider->method('updateZoneAccount')->willReturnCallback(function (int $id, string $account): bool {
            if ($this->failPush) {
                throw new PDOException('database is locked');
            }
            $this->pushes[] = [$id, $account, $this->transaction->inTransaction()];
            return true;
        });

        $this->config = new FakeConfiguration(['dns' => ['sync_zone_owner_to_account' => true]]);
        $this->transaction = new PdoTransaction($this->db);
        $this->repository = new ApiZoneRepository($this->db, $provider, 'sqlite', $this->config);
    }

    private function guard(): ZoneOwnershipGuard
    {
        $zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $zoneGroups->method('findByDomainId')->willReturn([]);

        return new ZoneOwnershipGuard($this->repository, $zoneGroups, new ZoneOwnershipModeService($this->config), $this->transaction);
    }

    private function limit(): ZoneOwnershipLimit
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findZoneLimit')->willReturn(10);
        $users->method('getDirectlyOwnedZoneIds')->willReturn([]);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('isAdmin')->willReturn(false);

        return new ZoneOwnershipLimit(
            $users,
            $this->createMock(UserGroupRepositoryInterface::class),
            $this->createMock(ZoneGroupRepositoryInterface::class),
            $permissions,
            $this->config,
            $this->transaction
        );
    }

    private static function deadlock(): PDOException
    {
        $e = new PDOException('Deadlock found');
        $e->errorInfo = ['40001', 1213, 'Deadlock found'];

        return $e;
    }

    #[Test]
    public function removingAnOwnerThroughTheGuardPushesOnceAfterTheCommit(): void
    {
        $this->assertTrue($this->guard()->removeUserOwner(4, 2));

        $this->assertSame([[4, 'primary', false]], $this->pushes);
    }

    #[Test]
    public function addingAnOwnerJoinedIntoALimitTransactionPushesOnceAfterTheCommit(): void
    {
        $result = $this->limit()->addUserOwner(3, fn(): bool => $this->repository->addOwnerToZone(4, 3), 4);

        $this->assertTrue($result);
        $this->assertCount(1, $this->pushes);
        $this->assertFalse($this->pushes[0][2]);
    }

    #[Test]
    public function aRolledBackWritePushesNothing(): void
    {
        $this->transaction->begin();
        $this->repository->addOwnerToZone(4, 3);
        $this->repository->removeOwnerFromZone(4, 2);
        $this->assertSame([], $this->pushes);
        $this->transaction->rollBack();

        $this->assertSame([], $this->pushes);
        $this->assertFalse($this->repository->isUserZoneOwner(4, 3));
    }

    #[Test]
    public function aReplayedTransactionPushesOnce(): void
    {
        $attempts = 0;
        DeadlockRetry::run(function () use (&$attempts): void {
            $attempts++;
            $this->transaction->begin();
            try {
                $this->repository->addOwnerToZone(4, 3);
                if ($attempts === 1) {
                    throw self::deadlock();
                }
                $this->transaction->commit();
            } catch (\Throwable $e) {
                if ($this->transaction->inTransaction()) {
                    $this->transaction->rollBack();
                }
                throw $e;
            }
        }, static fn(int $try) => null);

        $this->assertSame(2, $attempts);
        $this->assertCount(1, $this->pushes);
    }

    #[Test]
    public function severalChangesToOneZoneInOneTransactionPushOnce(): void
    {
        $this->transaction->begin();
        $this->repository->addOwnerToZone(4, 3);
        $this->repository->removeOwnerFromZone(4, 2);
        $this->transaction->commit();

        $this->assertCount(1, $this->pushes);
        $this->assertFalse($this->pushes[0][2]);
    }

    #[Test]
    public function aChangeOutsideAnyTransactionPushesAtOnce(): void
    {
        $this->repository->removeOwnerFromZone(4, 2);

        $this->assertCount(1, $this->pushes);
    }

    #[Test]
    public function addingAnOwnerInItsOwnTransactionPushesAfterTheCommit(): void
    {
        $this->assertTrue($this->repository->addOwnerToZone(4, 3));

        $this->assertSame([[4, 'primary', false]], $this->pushes);
    }

    #[Test]
    public function aPushThatFailsAfterTheCommitLeavesTheOwnerChangeReportedAsDone(): void
    {
        $this->failPush = true;
        $log = ini_set('error_log', '/dev/null');
        try {
            $this->assertTrue($this->repository->addOwnerToZone(4, 3));
        } finally {
            ini_set('error_log', (string)$log);
        }

        $this->assertTrue($this->repository->isUserZoneOwner(4, 3));
    }
}
