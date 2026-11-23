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
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Query\RecordSearch;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * The record search total used to match record comments even with show_record_comments off,
 * while the rows ignored them, so the count answered for hidden comment text.
 */
class RecordSearchCountCommentGateTest extends TestCase
{
    private function countQuery(bool $showRecordComments): string
    {
        $captured = '';
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            static fn(string $group, string $key, $default = null) => match ([$group, $key]) {
                ['database', 'type'] => 'mysql',
                ['interface', 'show_record_comments'] => $showRecordComments,
                default => '',
            }
        );

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(0);
        $db = $this->createMock(PDO::class);
        $db->method('query')->willReturn($stmt);
        $db->method('prepare')->willReturnCallback(function (string $query) use (&$captured, $stmt) {
            $captured = $query;
            return $stmt;
        });
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(42);

        $search = new RecordSearch($db, $config, 'mysql', null, $userContext);
        $search->getTotalRecords(['query' => 'x', 'reverse' => false, 'comments' => true, 'wildcard' => true], 'all', false);

        return $captured;
    }

    public function testCountIgnoresCommentsWhileTheyAreHidden(): void
    {
        $this->assertStringNotContainsString('c.comment', $this->countQuery(false));
    }

    public function testCountMatchesCommentsWhileTheyAreShown(): void
    {
        $this->assertStringContainsString('c.comment LIKE', $this->countQuery(true));
    }
}
