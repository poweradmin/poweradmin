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

use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Application\Query\RecordSearch;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * The record search total used to match record comments even with iface_record_comments off,
 * while the rows ignored them, so the count answered for hidden comment text.
 */
class RecordSearchCountCommentGateTest extends TestCase
{
    private function countQuery(bool $showRecordComments): string
    {
        $captured = '';
        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key) => $key === 'iface_record_comments' ? $showRecordComments : ''
        );

        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(static fn($value): string => "'" . $value . "'");
        $db->method('queryOne')->willReturnCallback(function (string $query) use (&$captured): int {
            $captured = $query;
            return 0;
        });

        $search = new RecordSearch($db, $config, 'mysql');
        $search->getFoundRecords(['reverse' => false, 'comments' => true], '%x%', '', 'all', false);

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
