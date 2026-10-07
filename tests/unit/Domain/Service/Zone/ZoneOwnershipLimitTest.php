<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Service\Zone\ZoneLimitBreach;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;

class ZoneOwnershipLimitTest extends TestCase
{
    private const SUPERUSER = 1;
    private const ALICE = 2;
    private const BOB = 3;

    /** @var array<int, ?int> */
    private array $userLimits = [];
    /** @var array<int, list<int>> */
    private array $owned = [];
    /** @var array<int, UserGroup> */
    private array $groups = [];
    /** @var array<int, int> */
    private array $groupZones = [];
    /** @var array<string, mixed> */
    private array $settings = [];
    /** @var list<array{string, int, ?int}> */
    private array $writes = [];
    /** @var list<string> Transaction calls, row locks and count reads, in order */
    private array $log = [];
    /** Whether an outer transaction is already open */
    private bool $outerTransaction = false;
    /** @var list<string> "zoneId:groupId" grants that already exist */
    private array $grants = [];

    private function service(bool $withTransaction = false): ZoneOwnershipLimit
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findZoneLimit')->willReturnCallback(fn(int $id) => $this->userLimits[$id] ?? null);
        $users->method('getDirectlyOwnedZoneIds')->willReturnCallback(function (int $id) {
            $this->log[] = "count:user:$id";
            return $this->owned[$id] ?? [];
        });
        $users->method('lockForZoneLimit')->willReturnCallback(function (int $id): void {
            $this->log[] = "lock:user:$id";
        });
        $users->method('countDirectlyOwnedZones')->willReturnCallback(
            fn(array $ids) => array_combine($ids, array_map(fn(int $id) => count($this->owned[$id] ?? []), $ids))
        );
        $users->method('findZoneLimits')->willReturnCallback(
            fn(array $ids) => array_combine($ids, array_map(fn(int $id) => $this->userLimits[$id] ?? null, $ids))
        );
        $users->method('getUserById')->willReturnCallback(
            fn(int $id) => $id === 99 ? null : ['id' => $id, 'username' => [self::ALICE => 'alice', self::BOB => 'bob'][$id] ?? 'admin']
        );
        $users->method('setZoneLimit')->willReturnCallback(function (int $id, ?int $limit) {
            $this->writes[] = ['user', $id, $limit];
            return true;
        });

        $groups = $this->createMock(UserGroupRepositoryInterface::class);
        $groups->method('findById')->willReturnCallback(fn(int $id) => $this->groups[$id] ?? null);
        $groups->method('lockForZoneLimit')->willReturnCallback(function (int $id): void {
            $this->log[] = "lock:group:$id";
        });
        $groups->method('setZoneLimit')->willReturnCallback(function (int $id, ?int $limit) {
            $this->writes[] = ['group', $id, $limit];
            return true;
        });

        $zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $zoneGroups->method('countGrantedZones')->willReturnCallback(function (int $id) {
            $this->log[] = "count:group:$id";
            return $this->groupZones[$id] ?? 0;
        });
        $zoneGroups->method('exists')->willReturnCallback(fn(int $zoneId, int $groupId): bool => in_array("$zoneId:$groupId", $this->grants, true));

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('isAdmin')->willReturnCallback(fn(int $id) => $id === self::SUPERUSER);

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null) => $this->settings[$key] ?? $default);

        $transaction = null;
        if ($withTransaction) {
            $transaction = $this->createMock(TransactionInterface::class);
            $transaction->method('inTransaction')->willReturnCallback(fn(): bool => $this->outerTransaction || in_array('begin', $this->log, true) && !in_array('commit', $this->log, true) && !in_array('rollBack', $this->log, true));
            foreach (['begin', 'commit', 'rollBack'] as $call) {
                $transaction->method($call)->willReturnCallback(function () use ($call): void {
                    $this->log[] = $call;
                });
            }
        }

        return new ZoneOwnershipLimit($users, $groups, $zoneGroups, $permissions, $config, $transaction);
    }

    public function testNoLimitAnywhereMeansUnlimited(): void
    {
        $this->owned[self::ALICE] = range(1, 500);

        $this->assertNull($this->service()->userLimit(self::ALICE));
        $this->assertNull($this->service()->userBreach(self::ALICE));
        $this->assertNull($this->service()->userRemaining(self::ALICE));
    }

    public function testOwnLimitWinsOverTheDefault(): void
    {
        $this->settings['default_max_zones_per_user'] = 10;
        $this->userLimits[self::ALICE] = 2;

        $this->assertSame(2, $this->service()->userLimit(self::ALICE));
        $this->assertSame(10, $this->service()->userLimit(self::BOB));
    }

    public function testZeroIsALimitNotUnlimited(): void
    {
        $this->settings['default_max_zones_per_user'] = 0;

        $breach = $this->service()->userBreach(self::ALICE);

        $this->assertInstanceOf(ZoneLimitBreach::class, $breach);
        $this->assertSame(0, $breach->limit);
    }

    public function testANonIntegerDefaultIsIgnored(): void
    {
        $this->settings['default_max_zones_per_user'] = '5';

        $this->assertNull($this->service()->userLimit(self::ALICE));
    }

    public function testSuperusersAreNeverLimited(): void
    {
        $this->settings['default_max_zones_per_user'] = 0;
        $this->userLimits[self::SUPERUSER] = 0;

        $this->assertNull($this->service()->userLimit(self::SUPERUSER));
        $this->assertNull($this->service()->userBreach(self::SUPERUSER));
    }

    public function testBreachOnlyPastTheLimit(): void
    {
        $this->userLimits[self::ALICE] = 3;
        $this->owned[self::ALICE] = [10, 11];

        $this->assertNull($this->service()->userBreach(self::ALICE));
        $this->assertSame(1, $this->service()->userRemaining(self::ALICE));

        $this->owned[self::ALICE] = [10, 11, 12];
        $breach = $this->service()->userBreach(self::ALICE);

        $this->assertNotNull($breach);
        $this->assertSame(['user', 'alice', 3, 3], [$breach->subject, $breach->name, $breach->owned, $breach->limit]);
        $this->assertSame('Zone limit reached: user alice owns 3 of 3 zones.', $breach->message());
        $this->assertSame(0, $this->service()->userRemaining(self::ALICE));
    }

    public function testRemainingNeverGoesNegativeAfterALimitWasLowered(): void
    {
        $this->userLimits[self::ALICE] = 1;
        $this->owned[self::ALICE] = [10, 11, 12];

        $this->assertSame(0, $this->service()->userRemaining(self::ALICE));
    }

    public function testGroupUsesItsOwnLimitThenTheGroupDefault(): void
    {
        $this->groups[5] = new UserGroup(5, 'ops', null, 1, null, null, null, 2);
        $this->groups[6] = new UserGroup(6, 'dev', null, 1);
        $this->groupZones = [5 => 2, 6 => 4];
        $this->settings['default_max_zones_per_group'] = 4;

        $breach = $this->service()->groupBreach(5);
        $this->assertNotNull($breach);
        $this->assertSame(['group', 'ops', 2, 2], [$breach->subject, $breach->name, $breach->owned, $breach->limit]);
        $this->assertSame(4, $this->service()->groupLimit(6));
        $this->assertNotNull($this->service()->groupBreach(6));
    }

    public function testNewZoneChecksTheUserOwnerThenEachGroup(): void
    {
        $this->groups[5] = new UserGroup(5, 'ops', null, 1, null, null, null, 0);

        $this->assertNull($this->service()->newZoneBreach(self::ALICE, []));
        $this->assertNull($this->service()->newZoneBreach(null, []));
        $this->assertSame('ops', $this->service()->newZoneBreach(self::ALICE, [5, 5])?->name);

        $this->userLimits[self::ALICE] = 0;
        $this->assertSame('alice', $this->service()->newZoneBreach(self::ALICE, [5])?->name);
    }

    public function testTransferCountsOnlyZonesTheReceiverDoesNotOwnYet(): void
    {
        $this->userLimits[self::BOB] = 3;
        $this->owned[self::ALICE] = [1, 2, 3];
        $this->owned[self::BOB] = [2, 3];

        // Bob gains only zone 1: 2 + 1 = 3, within the limit
        $this->assertNull($this->service()->transferBreach(self::ALICE, self::BOB));

        $this->owned[self::ALICE] = [1, 4];
        $limits = $this->service();
        $breach = $limits->transferBreach(self::ALICE, self::BOB);
        $this->assertNotNull($breach);
        $this->assertSame(2, $breach->owned);
    }

    public function testZonesAlreadyOwnedNeverBreach(): void
    {
        $this->userLimits[self::ALICE] = 0;
        $this->owned[self::ALICE] = [7];

        $this->assertNull($this->service()->zonesBreach(self::ALICE, [7, 7]));
        $this->assertNotNull($this->service()->zonesBreach(self::ALICE, [7, 8]));
    }

    public function testOnlySuperusersSetLimits(): void
    {
        $result = $this->service()->setUserLimit(self::ALICE, self::BOB, 100);

        $this->assertFalse($result['success']);
        $this->assertSame(Refusal::FORBIDDEN, $result['refusal']);
        $this->assertSame(ZoneOwnershipLimit::ERR_FORBIDDEN, $result['code']);
        $this->assertFalse($this->service()->setGroupLimit(self::ALICE, 5, null)['success']);
        $this->assertSame([], $this->writes);
    }

    public function testSettersRefuseOutOfRangeValues(): void
    {
        foreach ([-1, ZoneOwnershipLimit::MAX_LIMIT + 1] as $limit) {
            $result = $this->service()->setUserLimit(self::SUPERUSER, self::BOB, $limit);
            $this->assertSame(Refusal::INVALID_INPUT, $result['refusal']);
        }
        $this->assertSame([], $this->writes);
    }

    public function testSettersRefuseAMissingTarget(): void
    {
        $this->assertSame(Refusal::NOT_FOUND, $this->service()->setUserLimit(self::SUPERUSER, 99, 1)['refusal']);
        $this->assertSame(Refusal::NOT_FOUND, $this->service()->setGroupLimit(self::SUPERUSER, 42, 1)['refusal']);
        $this->assertSame([], $this->writes);
    }

    public function testSuperuserWritesAndClearsLimits(): void
    {
        $this->groups[5] = new UserGroup(5, 'ops', null, 1);

        $this->assertTrue($this->service()->setUserLimit(self::SUPERUSER, self::BOB, 0)['success']);
        $this->assertTrue($this->service()->setGroupLimit(self::SUPERUSER, 5, null)['success']);
        $this->assertSame([['user', self::BOB, 0], ['group', 5, null]], $this->writes);
    }

    public function testUserUsageMatchesThePerUserAnswers(): void
    {
        $this->settings['default_max_zones_per_user'] = 5;
        $this->userLimits[self::ALICE] = 2;
        $this->userLimits[self::SUPERUSER] = 0;
        $this->owned = [self::ALICE => [1, 2, 3], self::SUPERUSER => [4]];

        $this->assertSame([
            self::SUPERUSER => ['owned' => 1, 'limit' => null],
            self::ALICE => ['owned' => 3, 'limit' => 2],
            self::BOB => ['owned' => 0, 'limit' => 5],
        ], $this->service()->userUsage([self::SUPERUSER, self::ALICE, self::BOB]));
    }

    public function testAGrantToUnlimitedOwnersOpensNoTransactionAndTakesNoLock(): void
    {
        $result = $this->service(true)->addUserOwner(self::ALICE, fn(): string => 'written');

        $this->assertSame('written', $result);
        $this->assertSame([], array_values(array_filter($this->log, fn(string $e): bool => !str_starts_with($e, 'count:'))));
    }

    public function testALimitedOwnerIsLockedBeforeTheCountAndCommitted(): void
    {
        $this->userLimits[self::ALICE] = 3;
        $this->owned[self::ALICE] = [1];

        $result = $this->service(true)->addUserOwner(self::ALICE, function (): string {
            $this->log[] = 'write';
            return 'written';
        });

        $this->assertSame('written', $result);
        $this->assertSame(['begin', 'lock:user:' . self::ALICE, 'count:user:' . self::ALICE, 'write', 'commit'], $this->log);
    }

    public function testABreachUnderTheLockRollsBackWithoutWriting(): void
    {
        $this->userLimits[self::ALICE] = 1;
        $this->owned[self::ALICE] = [1];

        $result = $this->service(true)->addUserOwner(self::ALICE, function (): string {
            $this->log[] = 'write';
            return 'written';
        });

        $this->assertInstanceOf(ZoneLimitBreach::class, $result);
        $this->assertSame(['begin', 'lock:user:' . self::ALICE, 'count:user:' . self::ALICE, 'rollBack'], $this->log);
    }

    public function testAFailedWriteRollsBackAndRethrows(): void
    {
        $this->userLimits[self::ALICE] = 5;

        try {
            $this->service(true)->addUserOwner(self::ALICE, function (): never {
                throw new RuntimeException('write failed');
            });
            $this->fail('The write exception must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('write failed', $e->getMessage());
        }

        $this->assertSame('rollBack', end($this->log));
        $this->assertNotContains('commit', $this->log);
    }

    public function testAGrantInsideAnOuterTransactionJoinsIt(): void
    {
        $this->userLimits[self::ALICE] = 5;
        $this->outerTransaction = true;

        $this->service(true)->addUserOwner(self::ALICE, fn(): bool => true);
        $this->service(true)->addUserOwner(self::ALICE, fn(): bool => true);
        $this->userLimits[self::ALICE] = 0;
        $this->service(true)->addUserOwner(self::ALICE, fn(): bool => true);

        $this->assertSame([], array_values(array_intersect($this->log, ['begin', 'commit', 'rollBack'])));
        $this->assertContains('lock:user:' . self::ALICE, $this->log);
    }

    public function testGroupGrantsAndTransfersLockTheirOwner(): void
    {
        $this->groups[7] = new UserGroup(7, 'ops', null, 1, null, null, null, 4);
        $this->userLimits[self::BOB] = 9;
        $this->owned[self::ALICE] = [1, 2];

        $this->assertTrue($this->service(true)->addGroupOwner(7, fn(): bool => true));
        $this->assertTrue($this->service(true)->transferZones(self::ALICE, self::BOB, fn(): bool => true));

        $locks = array_values(array_filter($this->log, fn(string $e): bool => str_starts_with($e, 'lock:')));
        $this->assertSame(['lock:group:7', 'lock:user:' . self::BOB], $locks);
    }

    public function testNewZoneLocksTheOwnerAndEveryGroupInOrderWithoutATransaction(): void
    {
        $this->groups[9] = new UserGroup(9, 'b', null, 1);
        $this->groups[4] = new UserGroup(4, 'a', null, 1, null, null, null, 0);

        $breach = $this->service(true)->lockedNewZoneBreach(self::BOB, [9, 4, 9]);

        $this->assertSame('a', $breach?->name);
        $locks = array_values(array_filter($this->log, fn(string $e): bool => str_starts_with($e, 'lock:')));
        $this->assertSame(['lock:user:' . self::BOB, 'lock:group:4', 'lock:group:9'], $locks);
        $this->assertSame([], array_values(array_intersect($this->log, ['begin', 'commit', 'rollBack'])));
        $firstCount = array_search('count:group:4', $this->log, true);
        $this->assertGreaterThan(array_search('lock:group:9', $this->log, true), $firstCount);
    }

    public function testWithoutATransactionPortTheGrantStillChecksTheLimit(): void
    {
        $this->userLimits[self::ALICE] = 0;

        $this->assertInstanceOf(ZoneLimitBreach::class, $this->service()->addUserOwner(self::ALICE, fn(): bool => true));
        $this->assertSame([], array_values(array_filter($this->log, fn(string $e): bool => str_starts_with($e, 'lock:'))));
    }

    public function testReassignmentsLockEveryLimitedOwnerAndCommitTogether(): void
    {
        $this->userLimits = [self::ALICE => 5, self::BOB => 5];

        $result = $this->service(true)->reassignZones([self::BOB => [7], self::ALICE => [8, 9], 0 => [10]], function (): bool {
            $this->log[] = 'write';
            return true;
        });

        $this->assertTrue($result);
        $this->assertSame('begin', $this->log[0]);
        $this->assertSame(['lock:user:' . self::ALICE, 'lock:user:' . self::BOB], array_values(array_filter($this->log, fn(string $e): bool => str_starts_with($e, 'lock:'))));
        $this->assertSame(['write', 'commit'], array_slice($this->log, -2));
    }

    public function testAReassignmentPastAnyLimitWritesNothing(): void
    {
        $this->userLimits = [self::ALICE => 5, self::BOB => 1];
        $this->owned[self::BOB] = [1];

        $result = $this->service(true)->reassignZones([self::ALICE => [8], self::BOB => [7]], function (): bool {
            $this->log[] = 'write';
            return true;
        });

        $this->assertInstanceOf(ZoneLimitBreach::class, $result);
        $this->assertSame('bob', $result->name);
        $this->assertNotContains('write', $this->log);
        $this->assertSame('rollBack', end($this->log));
    }

    public function testAReassignmentWriteReportingFailureRollsBack(): void
    {
        $this->userLimits[self::ALICE] = 5;

        $result = $this->service(true)->reassignZones([self::ALICE => [8]], fn(): bool => false);

        $this->assertFalse($result);
        $this->assertSame('rollBack', end($this->log));
        $this->assertNotContains('commit', $this->log);
    }

    public function testAZoneTheUserAlreadyOwnsIsNeverRefused(): void
    {
        $this->userLimits[self::ALICE] = 1;
        $this->owned[self::ALICE] = [8];

        // A concurrent request added the owner first; this one gains nothing and is not refused
        $this->assertSame('written', $this->service(true)->addUserOwner(self::ALICE, fn(): string => 'written', 8));
        $this->assertInstanceOf(ZoneLimitBreach::class, $this->service(true)->addUserOwner(self::ALICE, fn(): string => 'written', 9));
    }

    public function testAZoneTheGroupAlreadyHoldsIsNeverRefused(): void
    {
        $this->groups[5] = new UserGroup(5, 'ops', null, 1, null, null, null, 1);
        $this->groupZones[5] = 1;
        $this->grants = ['8:5'];

        $this->assertSame('written', $this->service(true)->addGroupOwner(5, fn(): string => 'written', 8));
        $this->assertInstanceOf(ZoneLimitBreach::class, $this->service(true)->addGroupOwner(5, fn(): string => 'written', 9));
    }

    public function testATransferThatPicksUpAZoneGrantedMeanwhileRollsBack(): void
    {
        $this->userLimits[self::BOB] = 2;
        $this->owned = [self::ALICE => [1], self::BOB => [2]];

        // The move also carries zone 3, granted to the sender after the first count
        $result = $this->service(true)->transferZones(self::ALICE, self::BOB, function (): bool {
            $this->owned[self::BOB] = [1, 2, 3];
            $this->log[] = 'write';
            return true;
        });

        $this->assertInstanceOf(ZoneLimitBreach::class, $result);
        $this->assertSame(3, $result->owned);
        $this->assertSame(['write', 'count:user:' . self::BOB, 'rollBack'], array_slice($this->log, -3));
    }

    public function testATransferWithinTheLimitCommits(): void
    {
        $this->userLimits[self::BOB] = 2;
        $this->owned = [self::ALICE => [1], self::BOB => [2]];

        $result = $this->service(true)->transferZones(self::ALICE, self::BOB, function (): bool {
            $this->owned[self::BOB] = [1, 2];
            return true;
        });

        $this->assertTrue($result);
        $this->assertSame('commit', end($this->log));
    }

    public function testAFailedTransferRollsBack(): void
    {
        $this->userLimits[self::BOB] = 5;

        $this->assertFalse($this->service(true)->transferZones(self::ALICE, self::BOB, fn(): bool => false));
        $this->assertSame('rollBack', end($this->log));
    }
}
