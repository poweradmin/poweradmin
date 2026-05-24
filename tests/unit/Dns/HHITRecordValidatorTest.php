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
use Poweradmin\Domain\Service\DnsValidation\HHITRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class HHITRecordValidatorTest extends TestCase
{
    private HHITRecordValidator $validator;

    protected function setUp(): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn('example.com');
        $this->validator = new HHITRecordValidator($config);
    }

    public function testValidBase64Content(): void
    {
        $blob = base64_encode(random_bytes(16));
        $result = $this->validator->validate($blob, 'drip.example.com', '', 3600, 86400);
        $this->assertTrue($result->isValid(), implode(';', $result->getErrors()));
        $data = $result->getData();
        $this->assertSame($blob, $data['content']);
        $this->assertSame(0, $data['prio']);
    }

    public function testAcceptsWhitespaceWrappedBase64AndStoresCompact(): void
    {
        $blob = base64_encode(random_bytes(64));
        $wrapped = chunk_split($blob, 32, "\n");
        $result = $this->validator->validate($wrapped, 'drip.example.com', '', 3600, 86400);
        $this->assertTrue($result->isValid(), implode(';', $result->getErrors()));
        $this->assertSame($blob, $result->getData()['content']);
    }

    public function testRejectsOversizedBlob(): void
    {
        // > 65535 raw bytes => > 87382 base64 chars, exceeds DNS RDATA cap.
        $oversized = str_repeat('A', 87384);
        $result = $this->validator->validate($oversized, 'drip.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsEmptyContent(): void
    {
        $result = $this->validator->validate('   ', 'drip.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsNonBase64Content(): void
    {
        $result = $this->validator->validate('not!base64!', 'drip.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsNonZeroPriority(): void
    {
        $blob = base64_encode('abc');
        $result = $this->validator->validate($blob, 'drip.example.com', 1, 3600, 86400);
        $this->assertFalse($result->isValid());
    }
}
