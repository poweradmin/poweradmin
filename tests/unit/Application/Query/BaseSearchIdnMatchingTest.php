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

namespace Poweradmin\Tests\Unit\Application\Query;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * A partial IDN query cannot match through LIKE, because the punycode of a substring
 * is not a substring of the punycode. These cover the decode-and-compare fallback.
 */
class BaseSearchIdnMatchingTest extends TestCase
{
    private TestableBaseSearch $search;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE domains (id integer primary key, name text)');
        $names = ['xn--mnchen-3ya.de', 'example.com', 'xn--80aswg.xn--p1ai', 'muenchen.de'];
        foreach ($names as $index => $name) {
            $stmt = $db->prepare('INSERT INTO domains (id, name) VALUES (?, ?)');
            $stmt->execute([$index + 1, $name]);
        }

        $this->search = new TestableBaseSearch($db, $this->createMock(ConfigurationManager::class), 'sqlite');
    }

    public function testPartialIdnQueryMatchesTheDecodedName(): void
    {
        $this->assertSame(
            ['xn--mnchen-3ya.de'],
            $this->search->exposeIdnNamesMatching('domains', 'name', 'münch')
        );
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $this->assertSame(
            ['xn--mnchen-3ya.de'],
            $this->search->exposeIdnNamesMatching('domains', 'name', 'MÜNCH')
        );
    }

    public function testNonLatinScriptsMatch(): void
    {
        $this->assertSame(
            ['xn--80aswg.xn--p1ai'],
            $this->search->exposeIdnNamesMatching('domains', 'name', 'сайт')
        );
    }

    public function testAnAsciiQueryProducesNoIdnMatches(): void
    {
        $this->assertSame([], $this->search->exposeIdnNamesMatching('domains', 'name', 'example'));
    }

    public function testAnEmptyQueryIsNotSearched(): void
    {
        $this->assertSame([], $this->search->exposeIdnNamesMatching('domains', 'name', '   '));
    }

    public function testConditionBindsOnePlaceholderPerMatch(): void
    {
        $params = [];
        $sql = $this->search->exposeIdnNameCondition('domains', 'name', 'münch', 'idnzone', $params);

        $this->assertSame(' OR name IN (:idnzone0)', $sql);
        $this->assertSame([':idnzone0' => 'xn--mnchen-3ya.de'], $params);
    }

    public function testConditionIsEmptyWhenNothingMatches(): void
    {
        $params = [];

        $this->assertSame('', $this->search->exposeIdnNameCondition('domains', 'name', 'example', 'idnzone', $params));
        $this->assertSame([], $params);
    }
}
