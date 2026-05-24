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
use Poweradmin\Domain\Service\DnsValidation\RESINFORecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class RESINFORecordValidatorTest extends TestCase
{
    private RESINFORecordValidator $validator;

    protected function setUp(): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn('example.com');
        $this->validator = new RESINFORecordValidator($config);
    }

    public function testValidSingleString(): void
    {
        $result = $this->validator->validate('"qnamemin exterr=15-17"', 'resolver.arpa', '', 3600, 86400);
        $this->assertTrue($result->isValid(), implode(';', $result->getErrors()));
        $data = $result->getData();
        $this->assertSame('"qnamemin exterr=15-17"', $data['content']);
        $this->assertSame(0, $data['prio']);
        $this->assertSame(3600, $data['ttl']);
    }

    public function testValidMultipleStrings(): void
    {
        $result = $this->validator->validate('"qnamemin" "exterr=15-17"', 'resolver.arpa', '', 3600, 86400);
        $this->assertTrue($result->isValid(), implode(';', $result->getErrors()));
    }

    public function testRejectsUnquotedContent(): void
    {
        $result = $this->validator->validate('qnamemin', 'resolver.arpa', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsEmptyContent(): void
    {
        $result = $this->validator->validate('   ', 'resolver.arpa', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsStringLongerThan255Bytes(): void
    {
        $content = '"' . str_repeat('a', 256) . '"';
        $result = $this->validator->validate($content, 'resolver.arpa', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsControlCharactersInContent(): void
    {
        $result = $this->validator->validate("\"bad\x01value\"", 'resolver.arpa', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsNonZeroPriority(): void
    {
        $result = $this->validator->validate('"qnamemin"', 'resolver.arpa', 5, 3600, 86400);
        $this->assertFalse($result->isValid());
    }
}
