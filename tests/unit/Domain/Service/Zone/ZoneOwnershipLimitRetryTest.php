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
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipLimit;
use TestHelpers\FakeConfiguration;

/**
 * A grant that loses a lock race is replayed whole when the limit owns the transaction,
 * and never when it only joined its caller's.
 */
#[CoversClass(ZoneOwnershipLimit::class)]
class ZoneOwnershipLimitRetryTest extends TestCase
{
    private const ALICE = 2;

    private object $tx;
    private int $limitReads = 0;
    /** @var list<string> */
    private array $writes = [];

    protected function setUp(): void
    {
        $this->tx = new class implements TransactionInterface {
            public bool $open = false;
            /** @var list<string> */
            public array $calls = [];

            public function begin(): void
            {
                $this->open = true;
                $this->calls[] = 'begin';
            }

            public function commit(): void
            {
                $this->open = false;
                $this->calls[] = 'commit';
            }

            public function rollBack(): void
            {
                $this->open = false;
                $this->calls[] = 'rollBack';
            }

            public function inTransaction(): bool
            {
                return $this->open;
            }
        };
    }

    private bool $unlimited = false;

    private function limit(bool $withRetry): ZoneOwnershipLimit
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findZoneLimit')->willReturnCallback(function (): ?int {
            $this->limitReads++;
            return $this->unlimited ? null : 5;
        });
        $users->method('getDirectlyOwnedZoneIds')->willReturn([]);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('isAdmin')->willReturn(false);

        $retry = $withRetry
            ? function (callable $attempt) {
                for ($try = 1;; $try++) {
                    try {
                        return $attempt();
                    } catch (RuntimeException $e) {
                        if ($try >= 3) {
                            throw $e;
                        }
                    }
                }
            }
            : null;

        return new ZoneOwnershipLimit(
            $users,
            $this->createMock(UserGroupRepositoryInterface::class),
            $this->createMock(ZoneGroupRepositoryInterface::class),
            $permissions,
            new FakeConfiguration(),
            $this->tx,
            $retry
        );
    }

    private function loseTheRaceOnce(): callable
    {
        return function (): string {
            $this->writes[] = 'write';
            if (count($this->writes) === 1) {
                throw new RuntimeException('deadlock');
            }
            return 'written';
        };
    }

    public function testALostRaceReplaysTheWholeTransactionAndCommitsOnce(): void
    {
        $result = $this->limit(true)->addUserOwner(self::ALICE, $this->loseTheRaceOnce());

        $this->assertSame('written', $result);
        $this->assertSame(['begin', 'rollBack', 'begin', 'commit'], $this->tx->calls);
        // One read decides whether to retry at all, then each attempt reads again before its transaction
        $this->assertSame(5, $this->limitReads);
    }

    public function testAJoinedTransactionIsNotReplayed(): void
    {
        $this->tx->open = true;

        try {
            $this->limit(true)->addUserOwner(self::ALICE, $this->loseTheRaceOnce());
            $this->fail('The failure must reach the transaction owner');
        } catch (RuntimeException) {
            $this->assertCount(1, $this->writes);
            $this->assertSame([], $this->tx->calls);
        }
    }

    public function testWithoutARetrySeamAFailureIsNotReplayed(): void
    {
        try {
            $this->limit(false)->addUserOwner(self::ALICE, $this->loseTheRaceOnce());
            $this->fail('The failure must propagate');
        } catch (RuntimeException) {
            $this->assertCount(1, $this->writes);
            $this->assertSame(['begin', 'rollBack'], $this->tx->calls);
        }
    }

    public function testAnOwnerWithoutALimitIsLeftToTheRepositoryRetry(): void
    {
        $this->unlimited = true;

        try {
            $this->limit(true)->addUserOwner(self::ALICE, $this->loseTheRaceOnce());
            $this->fail('The failure must propagate');
        } catch (RuntimeException) {
            $this->assertCount(1, $this->writes);
            $this->assertSame([], $this->tx->calls);
        }
    }
}
