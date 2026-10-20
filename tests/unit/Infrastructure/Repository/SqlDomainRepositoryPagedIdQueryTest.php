<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;

/**
 * The letter-filtered zone list pages through an inner id query. Column sorts use
 * DISTINCT, shaped by what the driver lets it ORDER BY; owner, record count and
 * group sorts rank the zones by that value before LIMIT (issue #1618).
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

    #[DataProvider('allDrivers')]
    public function testOwnerSortRanksThePageByOwnerBeforeLimit(string $dbType): void
    {
        $inner = $this->innerIdQuery($dbType, 'owner', 'DESC');

        $this->assertStringStartsWith('SELECT domains.id, domains.name, MAX(page_users.username) AS page_rank FROM domains LEFT JOIN zones page_zones', $inner);
        $this->assertStringEndsWith('GROUP BY domains.id, domains.name ORDER BY MAX(page_users.username) DESC, domains.name LIMIT 10 OFFSET 0', $inner);
    }

    #[DataProvider('allDrivers')]
    public function testRecordCountSortRanksThePageByCountBeforeLimit(string $dbType): void
    {
        $inner = $this->innerIdQuery($dbType, 'count_records', 'DESC');

        $this->assertStringEndsWith('GROUP BY domains.id, domains.name ORDER BY COUNT(DISTINCT page_records.id) DESC, domains.name LIMIT 10 OFFSET 0', $inner);
    }

    /** @return array<string, array{string}> */
    public static function allDrivers(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql'], 'sqlite' => ['sqlite']];
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
