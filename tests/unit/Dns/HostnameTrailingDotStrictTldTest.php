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

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\MXRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\NSRecordValidator;

class HostnameTrailingDotStrictTldTest extends TestCase
{
    private function hostname(): HostnameValidator
    {
        return new HostnameValidator(new HostnamePolicy(true, true));
    }

    private function mx(): MXRecordValidator
    {
        return new MXRecordValidator($this->hostname());
    }

    private function ns(): NSRecordValidator
    {
        return new NSRecordValidator($this->hostname());
    }

    public function testTargetsWithTrailingDotAreAcceptedUnderStrictTldCheck(): void
    {
        $this->assertTrue($this->hostname()->validate('example.com.')->isValid());
        $this->assertTrue($this->mx()->validate('example.com.', 'example.com', 10, 3600, 3600)->isValid());
        $this->assertTrue($this->ns()->validate('ns1.example.com.', 'example.com', '', 3600, 3600)->isValid());
    }

    public function testUnknownTldWithTrailingDotIsStillRefused(): void
    {
        $this->assertFalse($this->hostname()->validate('example.invalidtld.')->isValid());
        $this->assertFalse($this->mx()->validate('example.invalidtld.', 'example.com', 10, 3600, 3600)->isValid());
    }

    public function testDoubleTrailingDotIsStillRefused(): void
    {
        $this->assertFalse($this->hostname()->validate('example.com..')->isValid());
    }
}
