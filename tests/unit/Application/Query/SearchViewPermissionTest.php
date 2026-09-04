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
 */

namespace unit\Application\Query;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Query\RecordSearch;
use Poweradmin\Application\Query\ZoneSearch;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * A user holding the `search` permission but no view permission resolves to
 * permission_view 'none'. The generated WHERE clause must match nothing;
 * previously 'none' fell through unfiltered and exposed every zone/record.
 */
class SearchViewPermissionTest extends TestCase
{
    private function makeConfig(): ConfigurationManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function (string $group, string $key) {
            return $group === 'database' && $key === 'type' ? 'mysql' : '';
        });
        return $config;
    }

    private function makeDb(string &$captured): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchColumn')->willReturn(0);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $query) use (&$captured, $stmt) {
            $captured = $query;
            return $stmt;
        });
        return $db;
    }

    private function makeUserContext(): UserContextService
    {
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(42);
        return $userContext;
    }

    private function zoneQuery(string $permissionView, bool $count): string
    {
        $captured = '';
        $config = $this->makeConfig();
        $search = new ZoneSearch($this->makeDb($captured), $config, 'mysql', null, $this->makeUserContext());
        $parameters = ['comments' => false];

        if ($count) {
            $search->getFoundZones($parameters, '%test%', false, '', $permissionView);
        } else {
            $search->fetchZones($parameters, '%test%', false, '', $permissionView, 'name', 'DESC', 10, false, 1);
        }

        return $captured;
    }

    private function recordQuery(string $permissionView, bool $count): string
    {
        $captured = '';
        $config = $this->makeConfig();
        $search = new RecordSearch($this->makeDb($captured), $config, 'mysql', null, $this->makeUserContext());
        $parameters = ['comments' => false];

        if ($count) {
            $search->getFoundRecords($parameters, '%test%', false, '', $permissionView, false);
        } else {
            $search->fetchRecords($parameters, '%test%', false, '', $permissionView, false, 'name', 'DESC', 10, false, 1);
        }

        return $captured;
    }

    public static function queryBuilderProvider(): array
    {
        return [
            'zone fetch' => ['zone', false],
            'zone count' => ['zone', true],
            'record fetch' => ['record', false],
            'record count' => ['record', true],
        ];
    }

    #[DataProvider('queryBuilderProvider')]
    public function testNoneViewMatchesNothing(string $kind, bool $count): void
    {
        $query = $kind === 'zone' ? $this->zoneQuery('none', $count) : $this->recordQuery('none', $count);

        $this->assertStringContainsString('1=0', $query);
    }

    #[DataProvider('queryBuilderProvider')]
    public function testAllViewIsNotRestricted(string $kind, bool $count): void
    {
        $query = $kind === 'zone' ? $this->zoneQuery('all', $count) : $this->recordQuery('all', $count);

        $this->assertStringNotContainsString('1=0', $query);
        $this->assertStringNotContainsString('ugm.user_id', $query);
    }

    #[DataProvider('queryBuilderProvider')]
    public function testOwnViewFiltersByOwnership(string $kind, bool $count): void
    {
        $query = $kind === 'zone' ? $this->zoneQuery('own', $count) : $this->recordQuery('own', $count);

        $this->assertStringNotContainsString('1=0', $query);
        $this->assertStringContainsString('z.owner =', $query);
        $this->assertStringContainsString('ugm.user_id', $query);
    }
}
