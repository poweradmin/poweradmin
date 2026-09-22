<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;

/**
 * The letter-filtered zone list pages through an inner DISTINCT id query whose
 * shape depends on what the driver lets a DISTINCT query ORDER BY.
 */
class SqlDomainRepositoryPagedIdQueryTest extends TestCase
{
    /** @return list<string> Every SQL statement the listing prepared or ran */
    private function capturedQueries(string $dbType, string $sortby, string $direction = 'ASC'): array
    {
        $db = $this->createMock(PDO::class);
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(function ($group, $key, $default = null) use ($dbType) {
            if ($group === 'database' && $key === 'type') {
                return $dbType;
            }
            if ($group === 'database' && $key === 'pdns_db_name') {
                return null;
            }
            return $default === null && $group !== 'database' ? false : $default;
        });

        $captured = [];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $db->method('prepare')->willReturnCallback(function ($query) use ($stmt, &$captured) {
            $captured[] = $query;
            return $stmt;
        });
        $db->method('query')->willReturnCallback(function ($query) use ($stmt, &$captured) {
            $captured[] = $query;
            return $stmt;
        });
        $db->method('exec')->willReturn(0);

        (new SqlDomainRepository($db, $config))->getZones('all', 0, 'a', 0, 10, $sortby, $direction);

        return $captured;
    }

    private function innerIdQuery(string $dbType, string $sortby, string $direction = 'ASC'): string
    {
        $queries = $this->capturedQueries($dbType, $sortby, $direction);
        $listing = (string)end($queries);
        preg_match('/INNER JOIN \((.*)\) AS limited_domains/s', $listing, $m);
        $this->assertArrayHasKey(1, $m, 'the listing pages through an inner id query');

        return preg_replace('/\s+/', ' ', trim($m[1]));
    }

    public function testPostgresOrdersTheNamePageByThePlainColumn(): void
    {
        $inner = $this->innerIdQuery('pgsql', 'name', 'DESC');

        $this->assertStringStartsWith('SELECT DISTINCT domains.id, domains.name FROM domains', $inner);
        $this->assertStringEndsWith('ORDER BY domains.name DESC LIMIT 10 OFFSET 0', $inner);
    }

    #[DataProvider('naturalSortDrivers')]
    public function testOtherDriversOrderTheNamePageNaturally(string $dbType): void
    {
        $inner = $this->innerIdQuery($dbType, 'name');

        $this->assertStringStartsWith('SELECT DISTINCT domains.id, domains.name FROM domains', $inner);
        $this->assertStringContainsString('ORDER BY domains.name+0<>0 ASC, domains.name+0 ASC, domains.name ASC LIMIT 10', $inner);
    }

    /** @return array<string, array{string}> */
    public static function naturalSortDrivers(): array
    {
        return ['mysql' => ['mysql'], 'sqlite' => ['sqlite']];
    }

    #[DataProvider('selectedColumnDrivers')]
    public function testDriversThatNeedTheOrderColumnSelectedPageOwnerSortsByName(string $dbType): void
    {
        $inner = $this->innerIdQuery($dbType, 'owner', 'DESC');

        $this->assertStringStartsWith('SELECT DISTINCT domains.id, domains.name FROM domains', $inner);
        $this->assertStringEndsWith('ORDER BY domains.name DESC LIMIT 10 OFFSET 0', $inner);
    }

    /** @return array<string, array{string}> */
    public static function selectedColumnDrivers(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    public function testSqlitePagesOwnerSortsByIdAlone(): void
    {
        $inner = $this->innerIdQuery('sqlite', 'owner', 'DESC');

        $this->assertStringStartsWith('SELECT DISTINCT domains.id FROM domains', $inner);
        $this->assertStringEndsWith('ORDER BY domains.name LIMIT 10 OFFSET 0', $inner);
    }

    public function testTypeSortSelectsAndOrdersByTypeEverywhere(): void
    {
        foreach (['mysql', 'pgsql', 'sqlite'] as $dbType) {
            $inner = $this->innerIdQuery($dbType, 'type', 'DESC');

            $this->assertStringStartsWith('SELECT DISTINCT domains.id, domains.type FROM domains', $inner, $dbType);
            $this->assertStringEndsWith('ORDER BY domains.type DESC LIMIT 10 OFFSET 0', $inner, $dbType);
        }
    }
}
