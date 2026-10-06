<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\UserGroup;
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

    private function service(): ZoneOwnershipLimit
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findZoneLimit')->willReturnCallback(fn(int $id) => $this->userLimits[$id] ?? null);
        $users->method('getDirectlyOwnedZoneIds')->willReturnCallback(fn(int $id) => $this->owned[$id] ?? []);
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
        $groups->method('setZoneLimit')->willReturnCallback(function (int $id, ?int $limit) {
            $this->writes[] = ['group', $id, $limit];
            return true;
        });

        $zoneGroups = $this->createMock(ZoneGroupRepositoryInterface::class);
        $zoneGroups->method('countGrantedZones')->willReturnCallback(fn(int $id) => $this->groupZones[$id] ?? 0);

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('isAdmin')->willReturnCallback(fn(int $id) => $id === self::SUPERUSER);

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null) => $this->settings[$key] ?? $default);

        return new ZoneOwnershipLimit($users, $groups, $zoneGroups, $permissions, $config);
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
}
