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

use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Application\Query\RecordSearch;
use Poweradmin\Application\Query\ZoneSearch;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * A user holding the `search` permission but no view permission resolves to
 * permission_view 'none'. The search builders must return nothing without
 * running a query; previously 'none' fell through unfiltered and exposed
 * every zone/record in the database.
 */
class SearchViewPermissionTest extends TestCase
{
    private array $parameters = [
        'reverse' => false,
        'comments' => false,
        'wildcard' => true,
    ];

    private function config(): AppConfiguration
    {
        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturn('');
        return $config;
    }

    public function testFetchZonesReturnsNothingForNoneView(): void
    {
        $db = $this->createMock(PDOLayer::class);
        $db->expects($this->never())->method('query');

        $search = new ZoneSearch($db, $this->config(), 'mysql');
        $result = $search->fetchZones($this->parameters, '%test%', false, '', 'none', 'name', 'DESC', 10, false, 1);

        $this->assertSame([], $result);
    }

    public function testGetFoundZonesCountsNothingForNoneView(): void
    {
        $db = $this->createMock(PDOLayer::class);
        $db->expects($this->never())->method('queryOne');

        $search = new ZoneSearch($db, $this->config(), 'mysql');
        $result = $search->getFoundZones($this->parameters, '%test%', '', 'none');

        $this->assertSame(0, $result);
    }

    public function testFetchRecordsReturnsNothingForNoneView(): void
    {
        $db = $this->createMock(PDOLayer::class);
        $db->expects($this->never())->method('query');

        $search = new RecordSearch($db, $this->config(), 'mysql');
        $result = $search->fetchRecords($this->parameters, '%test%', false, '', 'none', false, 'name', 'DESC', 10, false, 1);

        $this->assertSame([], $result);
    }

    public function testGetFoundRecordsCountsNothingForNoneView(): void
    {
        $db = $this->createMock(PDOLayer::class);
        $db->expects($this->never())->method('queryOne');

        $search = new RecordSearch($db, $this->config(), 'mysql');
        $result = $search->getFoundRecords($this->parameters, '%test%', '', 'none', false);

        $this->assertSame(0, $result);
    }

    public function testOwnerFilterCoversCommentMatchesForOwnView(): void
    {
        $_SESSION['userid'] = 7;
        $captured = '';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);

        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(fn($value, $type = null) => $type === 'integer' ? (string)(int)$value : "'x'");
        $db->method('query')->willReturnCallback(function (string $sql) use (&$captured, $stmt) {
            $captured = $sql;
            return $stmt;
        });

        $search = new ZoneSearch($db, $this->config(), 'mysql');
        $parameters = ['reverse' => false, 'comments' => true, 'wildcard' => true];
        $search->fetchZones($parameters, '%test%', false, '', 'own', 'name', 'DESC', 10, true, 1);

        // The comment match must sit inside the parenthesised name group, so the owner
        // predicate applies to both halves and an 'own' user never sees foreign zones
        $this->assertMatchesRegularExpression(
            "/WHERE\s+\(\(domains\.name LIKE 'x'\)\s+OR z\.comment LIKE 'x'\)\s+AND z\.owner = 7/",
            $captured
        );
    }

    public function testOwnerFilterCoversCommentMatchesInTheCount(): void
    {
        $_SESSION['userid'] = 7;
        $captured = '';

        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(fn($value, $type = null) => $type === 'integer' ? (string)(int)$value : "'x'");
        $db->method('queryOne')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return 0;
        });

        $search = new ZoneSearch($db, $this->config(), 'mysql');
        $parameters = ['reverse' => false, 'comments' => true, 'wildcard' => true];
        $search->getFoundZones($parameters, '%test%', '', 'own');

        $this->assertMatchesRegularExpression(
            "/WHERE\s+\(\(domains\.name LIKE 'x'\)\s+OR z\.comment LIKE 'x'\)\s+AND z\.owner = 7/",
            $captured
        );
    }

    public function testOwnerFilterCoversCommentMatchesForRecordsForOwnView(): void
    {
        $_SESSION['userid'] = 7;
        $captured = '';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);

        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(fn($value, $type = null) => $type === 'integer' ? (string)(int)$value : "'x'");
        $db->method('query')->willReturnCallback(function (string $sql) use (&$captured, $stmt) {
            $captured = $sql;
            return $stmt;
        });

        $search = new RecordSearch($db, $this->config(), 'mysql');
        $parameters = ['reverse' => false, 'comments' => true, 'wildcard' => true];
        $search->fetchRecords($parameters, '%test%', false, '', 'own', false, 'name', 'DESC', 10, true, 1);

        $this->assertMatchesRegularExpression(
            "/WHERE\s+\(\(records\.name LIKE 'x' OR records\.content LIKE 'x'\) OR c\.comment LIKE 'x'\)\s+AND z\.owner = 7/",
            $captured
        );
    }

    public function testOwnerFilterCoversCommentMatchesInTheRecordCount(): void
    {
        $_SESSION['userid'] = 7;
        $captured = '';

        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(fn($value, $type = null) => $type === 'integer' ? (string)(int)$value : "'x'");
        $db->method('queryOne')->willReturnCallback(function (string $sql) use (&$captured) {
            $captured = $sql;
            return 0;
        });

        $search = new RecordSearch($db, $this->config(), 'mysql');
        $parameters = ['reverse' => false, 'comments' => true, 'wildcard' => true];
        $search->getFoundRecords($parameters, '%test%', '', 'own', false);

        $this->assertMatchesRegularExpression(
            "/WHERE\s+\(\(records\.name LIKE 'x' OR records\.content LIKE 'x'\) OR c\.comment LIKE 'x'\)\s+AND z\.owner = 7/",
            $captured
        );
    }

    public function testFetchZonesStillQueriesForAllView(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);

        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturn("'x'");
        $db->expects($this->once())->method('query')->willReturn($stmt);

        $search = new ZoneSearch($db, $this->config(), 'mysql');
        $result = $search->fetchZones($this->parameters, '%test%', false, '', 'all', 'name', 'DESC', 10, false, 1);

        $this->assertSame([], $result);
    }
}
