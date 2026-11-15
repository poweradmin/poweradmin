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
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * The max_keys_per_user count and the insert run in one transaction after the
 * owner's users row is locked, so parallel requests cannot all pass the count.
 */
class ApiKeyServiceCapLockTest extends TestCase
{
    /** @var list<string> */
    public static array $log = [];

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCountAndInsertRunInsideTheOwnerRowLockTransaction(): void
    {
        [$service, $repo] = $this->build(2);
        $repo->method('countByUser')->willReturnCallback(function () {
            self::$log[] = 'count';

            return 2;
        });
        $repo->expects($this->never())->method('save');

        $this->assertNull($service->createApiKey('k'));
        $this->assertSame(['begin', 'exec:UPDATE users SET id = id WHERE 0', 'sql:SELECT id FROM users WHERE id = :id', 'count', 'rollback'], self::$log);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInsertIsCommittedAfterTheLockedCount(): void
    {
        [$service, $repo] = $this->build(2);
        $repo->method('countByUser')->willReturnCallback(function () {
            self::$log[] = 'count';

            return 1;
        });
        $repo->method('save')->willReturnCallback(function (ApiKey $key) {
            self::$log[] = 'save';

            return $key;
        });

        $this->assertNotNull($service->createApiKey('k'));
        $this->assertSame(['begin', 'exec:UPDATE users SET id = id WHERE 0', 'sql:SELECT id FROM users WHERE id = :id', 'count', 'save', 'commit'], self::$log);
    }

    /**
     * @return array{ApiKeyService, ApiKeyRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function build(int $max): array
    {
        self::$log = [];
        $pdo = new class ('sqlite::memory:') extends PDO {
            public function beginTransaction(): bool
            {
                ApiKeyServiceCapLockTest::$log[] = 'begin';

                return parent::beginTransaction();
            }

            public function commit(): bool
            {
                ApiKeyServiceCapLockTest::$log[] = 'commit';

                return parent::commit();
            }

            public function rollBack(): bool
            {
                ApiKeyServiceCapLockTest::$log[] = 'rollback';

                return parent::rollBack();
            }

            public function exec(string $statement): int|false
            {
                ApiKeyServiceCapLockTest::$log[] = 'exec:' . $statement;

                return parent::exec($statement);
            }

            #[\ReturnTypeWillChange]
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if (str_starts_with($query, 'SELECT id FROM users')) {
                    ApiKeyServiceCapLockTest::$log[] = 'sql:' . $query;
                }

                return parent::prepare($query, $options);
            }
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, perm_templ INTEGER)');
        $pdo->exec('CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE perm_templ (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)');
        $pdo->exec('CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)');
        $pdo->exec('CREATE TABLE user_groups (id INTEGER PRIMARY KEY, perm_templ INTEGER)');
        $pdo->exec("INSERT INTO perm_items VALUES (1, 'api_manage_keys')");
        $pdo->exec('INSERT INTO perm_templ VALUES (1)');
        $pdo->exec('INSERT INTO perm_templ_items VALUES (1, 1, 1)');
        $pdo->exec('INSERT INTO users VALUES (7, 1)');
        $_SESSION['userid'] = 7;
        self::$log = [];

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn (string $g, string $k, $d = null) => $k === 'max_keys_per_user' ? $max : true);
        $repo = $this->createMock(ApiKeyRepositoryInterface::class);

        return [new ApiKeyService($repo, $pdo, $config, $this->createMock(MessageService::class)), $repo];
    }
}
