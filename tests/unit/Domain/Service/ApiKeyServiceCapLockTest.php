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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionProperty;

/**
 * The max_keys_per_user count and the insert run in one transaction holding the
 * owner's row, so parallel requests cannot all pass the cap.
 */
class ApiKeyServiceCapLockTest extends TestCase
{
    private PDO $db;
    private array $inTransaction = [];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, active INTEGER)');
        $this->db->exec('INSERT INTO users VALUES (7, 1)');
    }

    private function service(int $existingKeys, bool $admin, ApiKeyRepositoryInterface $repo): ApiKeyService
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnMap([
            ['api', 'enabled', false, true],
            ['api', 'max_keys_per_user', 5, 5],
        ]);
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);

        $service = new ApiKeyService($repo, $this->db, $config, $this->createMock(MessageService::class), null, $userContext);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(fn(int $id, string $name): bool => $admin || $name === 'api_manage_keys');
        (new ReflectionProperty($service, 'permissionService'))->setValue($service, $permissions);

        return $service;
    }

    private function repo(int $existingKeys): ApiKeyRepositoryInterface
    {
        $repo = $this->createMock(ApiKeyRepositoryInterface::class);
        $repo->method('countByUser')->willReturnCallback(function () use ($existingKeys): int {
            $this->inTransaction['count'] = $this->db->inTransaction();
            return $existingKeys;
        });
        $repo->method('save')->willReturnCallback(function (ApiKey $key): ApiKey {
            $this->inTransaction['save'] = $this->db->inTransaction();
            $key->setId(1);
            return $key;
        });

        return $repo;
    }

    #[Test]
    public function testCountAndInsertShareOneTransaction(): void
    {
        $created = $this->service(2, false, $this->repo(2))->createApiKey('k');

        $this->assertNotNull($created);
        $this->assertSame(['count' => true, 'save' => true], $this->inTransaction);
        $this->assertFalse($this->db->inTransaction(), 'transaction must be closed after the insert');
    }

    #[Test]
    public function testRefusalAtTheCapRollsBackWithoutInserting(): void
    {
        $repo = $this->repo(5);
        $repo->expects($this->never())->method('save');

        $this->assertNull($this->service(5, false, $repo)->createApiKey('k'));
        $this->assertFalse($this->db->inTransaction());
    }

    #[Test]
    public function testZoneScopeFailureLeavesNoKeyRow(): void
    {
        $this->db->exec('CREATE TABLE api_keys (id INTEGER PRIMARY KEY)');
        $repo = $this->createMock(ApiKeyRepositoryInterface::class);
        $repo->method('zoneScopesReady')->willReturn(true);
        $repo->method('save')->willReturnCallback(function (ApiKey $key): ApiKey {
            $this->db->exec('INSERT INTO api_keys (id) VALUES (1)');
            $key->setId(1);
            return $key;
        });
        $repo->method('saveZoneIds')->willThrowException(new \RuntimeException('scope write failed'));

        try {
            $this->service(0, false, $repo)->createApiKey('k', null, false, null, [5]);
            $this->fail('the scope failure must surface');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM api_keys')->fetchColumn());
        $this->assertFalse($this->db->inTransaction());
    }

    #[Test]
    public function testSuperAdminSkipsTheCapButStillWritesInATransaction(): void
    {
        $this->assertNotNull($this->service(9, true, $this->repo(9))->createApiKey('k'));
        $this->assertSame(['save' => true], $this->inTransaction);
    }
}
