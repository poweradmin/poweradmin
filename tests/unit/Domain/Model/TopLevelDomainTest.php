<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\TopLevelDomain;

class TopLevelDomainTest extends TestCase
{
    protected function setUp(): void
    {
        TopLevelDomain::resetCache();
    }

    public function testValidCommonTlds(): void
    {
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.com'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.org'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.net'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.edu'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.gov'));
    }

    public function testValidCountryCodeTlds(): void
    {
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.uk'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.de'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.fr'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.jp'));
    }

    public function testValidNewTlds(): void
    {
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.app'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.dev'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.io'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.cloud'));
    }

    public function testSpecialTlds(): void
    {
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.test'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.localhost'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.invalid'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.example'));
    }

    public function testInvalidTlds(): void
    {
        $this->assertFalse(TopLevelDomain::isValidTopLevelDomain('example.notarealtld'));
        $this->assertFalse(TopLevelDomain::isValidTopLevelDomain('example.xyz123'));
        $this->assertFalse(TopLevelDomain::isValidTopLevelDomain('example.fake'));
    }

    public function testCaseInsensitive(): void
    {
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.COM'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.CoM'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.ORG'));
    }

    public function testIdnPunycodeTlds(): void
    {
        // International TLDs in punycode format
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.xn--lgbbat1ad8j'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('example.xn--fiqs8s'));
    }

    public function testSubdomains(): void
    {
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('www.example.com'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('mail.server.example.org'));
        $this->assertTrue(TopLevelDomain::isValidTopLevelDomain('deep.nested.subdomain.example.net'));
    }
}
