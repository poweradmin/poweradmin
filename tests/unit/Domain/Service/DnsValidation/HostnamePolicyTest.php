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

namespace Poweradmin\Tests\Unit\Domain\Service\DnsValidation;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use TestHelpers\FakeConfiguration;

class HostnamePolicyTest extends TestCase
{
    public function testDefaultsAreLenient(): void
    {
        $policy = new HostnamePolicy();

        $this->assertFalse($policy->topLevelTldCheck);
        $this->assertFalse($policy->strictTldCheck);
        $this->assertSame([], $policy->customTlds);
    }

    public function testFromConfigReadsTheThreeDnsKeys(): void
    {
        $policy = HostnamePolicy::fromConfig(new FakeConfiguration([
            'dns' => [
                'top_level_tld_check' => true,
                'strict_tld_check' => 1,
                'custom_tlds' => ['lan', 'CORP'],
            ],
        ]));

        $this->assertTrue($policy->topLevelTldCheck);
        $this->assertTrue($policy->strictTldCheck);
        $this->assertSame(['lan', 'CORP'], $policy->customTlds);
    }

    public function testFromConfigTreatsMissingAndMalformedValuesAsOff(): void
    {
        $policy = HostnamePolicy::fromConfig(new FakeConfiguration([
            'dns' => ['custom_tlds' => 'lan,corp'],
        ]));

        $this->assertFalse($policy->topLevelTldCheck);
        $this->assertFalse($policy->strictTldCheck);
        $this->assertSame([], $policy->customTlds);
    }

    public function testCustomTldMatchIsCaseInsensitive(): void
    {
        $policy = new HostnamePolicy(false, true, ['lan', 'CORP']);

        $this->assertTrue($policy->allowsCustomTld('LAN'));
        $this->assertTrue($policy->allowsCustomTld('corp'));
        $this->assertFalse($policy->allowsCustomTld('home'));
    }

    public function testStrictPolicyRejectsUnknownTldUnlessListed(): void
    {
        $validator = new HostnameValidator(new HostnamePolicy(true, true, ['lan']));

        $this->assertTrue($validator->validate('host.example.com')->isValid());
        $this->assertTrue($validator->validate('printer.office.lan')->isValid());
        $this->assertFalse($validator->validate('printer.office.home')->isValid());
        $this->assertFalse($validator->validate('localhost')->isValid());
    }
}
