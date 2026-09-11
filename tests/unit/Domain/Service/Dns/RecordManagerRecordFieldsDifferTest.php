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

namespace Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\RecordManager;

/**
 * The single-record edit page decides whether to bump the SOA serial by
 * comparing the stored row before and after the write. PDO returns every
 * column as a string while the API backend returns ints, so the comparison
 * has to be by value, not by type.
 */
class RecordManagerRecordFieldsDifferTest extends TestCase
{
    private const STORED = [
        'name' => 'www.example.com',
        'type' => 'A',
        'content' => '192.0.2.1',
        'ttl' => '3600',
        'prio' => '0',
        'disabled' => '0',
    ];

    #[Test]
    public function testIdenticalRowsDoNotDiffer(): void
    {
        $this->assertFalse(RecordManager::recordFieldsDiffer(self::STORED, self::STORED));
    }

    #[Test]
    public function testCaseAndScalarTypeDifferencesDoNotCount(): void
    {
        $after = ['name' => 'WWW.example.com', 'type' => 'a', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => false];

        $this->assertFalse(RecordManager::recordFieldsDiffer(self::STORED, $after));
    }

    #[Test]
    #[DataProvider('changedFieldProvider')]
    public function testAnyServedFieldChangeCounts(string $field, mixed $value): void
    {
        $after = self::STORED;
        $after[$field] = $value;

        $this->assertTrue(RecordManager::recordFieldsDiffer(self::STORED, $after));
    }

    public static function changedFieldProvider(): array
    {
        return [
            'name' => ['name', 'mail.example.com'],
            'type' => ['type', 'AAAA'],
            'content' => ['content', '192.0.2.2'],
            'ttl' => ['ttl', '300'],
            'prio' => ['prio', '10'],
            'disabled' => ['disabled', '1'],
        ];
    }

    #[Test]
    public function testMissingFieldsAreTreatedAsEmpty(): void
    {
        // API rows omit prio for types that have none; that is not a change
        $before = self::STORED;
        unset($before['prio']);

        $this->assertFalse(RecordManager::recordFieldsDiffer($before, self::STORED));
    }
}
