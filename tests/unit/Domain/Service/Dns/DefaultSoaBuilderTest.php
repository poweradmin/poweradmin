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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\DefaultSoaBuilder;
use TestHelpers\FakeConfiguration;

/**
 * The SOA a new zone and the consistency repair get when the settings leave fields out.
 */
class DefaultSoaBuilderTest extends TestCase
{
    public function testMissingTimersFallBackToTheDefaults(): void
    {
        $builder = new DefaultSoaBuilder(new FakeConfiguration(['dns' => ['ns1' => 'ns.example.net', 'hostmaster' => 'admin.example.net']]));

        $this->assertSame('ns.example.net admin.example.net ' . date('Ymd') . '00 28800 7200 604800 86400', $builder->content());
    }

    public function testTheTtlSettingIsReadAsANumber(): void
    {
        $this->assertSame(3600, (new DefaultSoaBuilder(new FakeConfiguration(['dns' => ['ttl' => '3600']])))->ttl());
    }
}
