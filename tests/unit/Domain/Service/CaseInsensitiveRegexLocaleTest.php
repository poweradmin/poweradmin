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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Web\SearchCriteria;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\Validator;
use TestHelpers\FakeConfiguration;

/**
 * Under a Turkish locale PCRE stops treating i and I as a case pair, so a
 * case-insensitive [a-z] class refused valid input containing the letter.
 */
class CaseInsensitiveRegexLocaleTest extends TestCase
{
    private string|false $previousLocale = false;

    protected function setUp(): void
    {
        $this->previousLocale = setlocale(LC_ALL, '0');
        if (setlocale(LC_ALL, 'tr_TR.UTF-8', 'tr_TR.utf8') === false) {
            $this->markTestSkipped('The tr_TR.UTF-8 locale is not installed');
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousLocale !== false) {
            setlocale(LC_ALL, $this->previousLocale);
        }
    }

    public function testAutoprimaryAccountWithLettersIAndIIsAccepted(): void
    {
        $this->assertTrue(SupermasterManager::validateAccount('Admin-nic'));
        $this->assertTrue(SupermasterManager::validateAccount('NIC_IDI'));
        $this->assertFalse(SupermasterManager::validateAccount('nic account'));
    }

    public function testEmailLocalPartWithLettersIAndIIsAccepted(): void
    {
        $validator = new Validator(new FakeConfiguration());

        $this->assertTrue($validator->isValidEmail('Irina.Iliin@example.com'));
        $this->assertFalse($validator->isValidEmail("irina\xff@example.com"));
    }

    public function testTypeFilterWithLetterIIsParsed(): void
    {
        $criteria = SearchCriteria::fromRequest(['query' => 'type:ALIAS host']);

        $this->assertSame('ALIAS', $criteria->toArray()['type_filter']);
    }
}
