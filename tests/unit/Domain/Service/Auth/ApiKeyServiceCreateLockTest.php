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

namespace Poweradmin\Tests\Unit\Domain\Service\Auth;

use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Repository\UserAdminInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Service\Auth\ApiKeyService;
use Poweradmin\Domain\Service\Auth\ApiKeyWriteResult;
use TestHelpers\FakeConfiguration;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\StubActor;

/**
 * Two parallel creates must not both read a count under the limit: the owner's row
 * is locked and the count and the insert share one transaction.
 */
class ApiKeyServiceCreateLockTest extends PermissionServiceTestCase
{
    private ApiKeyRepositoryInterface&MockObject $keys;
    private TransactionInterface&MockObject $transaction;
    private UserAdminInterface&MockObject $locks;
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->keys = $this->createMock(ApiKeyRepositoryInterface::class);
        $this->transaction = $this->createMock(TransactionInterface::class);
        $this->locks = $this->createMock(UserAdminInterface::class);

        $this->transaction->method('begin')->willReturnCallback(function (): void {
            $this->calls[] = 'begin';
        });
        $this->transaction->method('commit')->willReturnCallback(function (): void {
            $this->calls[] = 'commit';
        });
        $this->transaction->method('rollBack')->willReturnCallback(function (): void {
            $this->calls[] = 'rollBack';
        });
        $this->locks->method('lockForZoneLimit')->willReturnCallback(function (int $id): void {
            $this->calls[] = 'lock:' . $id;
        });
        $this->keys->method('countByUser')->willReturnCallback(function (): int {
            $this->calls[] = 'count';
            return 0;
        });
        $saved = $this->createMock(ApiKey::class);
        $saved->method('getId')->willReturn(99);
        $this->keys->method('save')->willReturnCallback(function () use ($saved): ApiKey {
            $this->calls[] = 'save';
            return $saved;
        });
    }

    public function testCountAndInsertRunInOneTransactionUnderTheOwnersRowLock(): void
    {
        $result = $this->service(5)->createApiKey('k');

        $this->assertTrue($result->success);
        $this->assertSame(['begin', 'lock:7', 'count', 'save', 'commit'], $this->calls);
    }

    public function testAKeyRefusedByTheLimitRollsBackWithoutInserting(): void
    {
        $keys = $this->createMock(ApiKeyRepositoryInterface::class);
        $keys->method('countByUser')->willReturn(2);
        $keys->expects($this->never())->method('save');
        $this->keys = $keys;

        $result = $this->service(2)->createApiKey('k');

        $this->assertFalse($result->success);
        $this->assertSame(ApiKeyWriteResult::ERR_LIMIT, $result->code);
        $this->assertSame(['begin', 'lock:7', 'rollBack'], $this->calls);
    }

    private function service(int $max): ApiKeyService
    {
        return new ApiKeyService(
            $this->keys,
            $this->createMock(UserLookupInterface::class),
            new FakeConfiguration(['api' => ['enabled' => true, 'max_keys_per_user' => $max]]),
            $this->buildPermissionService(permissionsByUser: [7 => [Permission::PERM_API_MANAGE_KEYS]]),
            new StubActor(7),
            null,
            $this->transaction,
            $this->locks
        );
    }
}
