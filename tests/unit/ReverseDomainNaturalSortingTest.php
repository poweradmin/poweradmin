<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\ReverseDomainNaturalSorting;

class ReverseDomainNaturalSortingTest extends TestCase
{
    private ReverseDomainNaturalSorting $reverseDomainNaturalSorting;

    protected function setUp(): void
    {
        $this->reverseDomainNaturalSorting = new ReverseDomainNaturalSorting();
    }

    public function testGetNaturalSortOrderForMysqlAndSqlite(): void
    {
        $field = 'domains.name';
        $dbTypes = ['mysql', 'mysqli', 'sqlite'];
        $expectedSql = "$field+0<>0 ASC, $field+0 ASC, $field ASC";

        foreach ($dbTypes as $dbType) {
            $result = $this->reverseDomainNaturalSorting->getNaturalSortOrder($field, $dbType);
            $this->assertEquals($expectedSql, $result, "Natural sort order for $dbType db type should match expected SQL");
        }
    }

    public function testGetNaturalSortOrderForPostgres(): void
    {
        $field = 'domains.name';
        $expectedSql = "SUBSTRING($field FROM '\.arpa$') ASC, LENGTH(SUBSTRING($field FROM '^[0-9]+')) ASC, $field ASC";

        $result = $this->reverseDomainNaturalSorting->getNaturalSortOrder($field, 'pgsql');
        $this->assertEquals($expectedSql, $result, "Natural sort order for PostgreSQL should match expected SQL");
    }

    public function testGetNaturalSortOrderWithCustomDirection(): void
    {
        $field = 'domains.name';
        $direction = 'DESC';
        $expectedSql = "$field+0<>0 DESC, $field+0 DESC, $field DESC";

        $result = $this->reverseDomainNaturalSorting->getNaturalSortOrder($field, 'mysql', $direction);
        $this->assertEquals($expectedSql, $result, "Natural sort order with custom direction should match expected SQL");
    }

    public function testGetNaturalSortOrderWithInvalidDirection(): void
    {
        $field = 'domains.name';
        $direction = 'INVALID';
        $expectedSql = "$field+0<>0 ASC, $field+0 ASC, $field ASC";

        $result = $this->reverseDomainNaturalSorting->getNaturalSortOrder($field, 'mysql', $direction);
        $this->assertEquals($expectedSql, $result, "Natural sort order with invalid direction should default to ASC");
    }

    public function testGetNaturalSortOrderWithUnknownDbType(): void
    {
        $field = 'domains.name';
        $dbType = 'unknown';
        $expectedSql = "$field ASC";

        $result = $this->reverseDomainNaturalSorting->getNaturalSortOrder($field, $dbType);
        $this->assertEquals($expectedSql, $result, "Natural sort order with unknown db type should use fallback SQL");
    }
}
