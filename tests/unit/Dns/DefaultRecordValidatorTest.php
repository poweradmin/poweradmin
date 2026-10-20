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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DefaultRecordValidator;

class DefaultRecordValidatorTest extends TestCase
{
    private DefaultRecordValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DefaultRecordValidator();
    }

    /**
     * Test validation with valid inputs
     */
    public function testValidateWithValidInputs(): void
    {
        $result = $this->validator->validate(
            'valid.content.example.com',  // content
            'record.example.com',        // name
            0,                          // prio
            3600,                       // ttl
            86400                       // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('valid.content.example.com', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['prio']);
    }

    /**
     * Test validation with empty content
     */
    public function testValidateWithEmptyContent(): void
    {
        $result = $this->validator->validate(
            '',                         // empty content
            'record.example.com',       // name
            0,                          // prio
            3600,                       // ttl
            86400                       // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Content field cannot be empty', $result->getFirstError());
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTtl(): void
    {
        $result = $this->validator->validate(
            'valid.content.example.com',  // content
            'record.example.com',        // name
            0,                          // prio
            -1,                         // invalid ttl
            86400                       // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    /**
     * Test validation with default TTL
     */
    public function testValidateWithDefaultTtl(): void
    {
        $result = $this->validator->validate(
            'valid.content.example.com',  // content
            'record.example.com',        // name
            0,                          // prio
            '',                         // empty ttl, should use default
            86400                       // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('valid.content.example.com', $data['content']);
        $this->assertEquals(86400, $data['ttl']);
        $this->assertEquals(0, $data['prio']);
    }

    /**
     * Test validation with custom priority
     */
    public function testValidateWithCustomPriority(): void
    {
        $result = $this->validator->validate(
            'valid.content.example.com',  // content
            'record.example.com',        // name
            10,                         // custom prio
            3600,                       // ttl
            86400                       // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('valid.content.example.com', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(10, $data['prio']);
    }

    /**
     * Test validation with empty priority (should default to 0)
     */
    public function testValidateWithEmptyPriority(): void
    {
        $result = $this->validator->validate(
            'valid.content.example.com',  // content
            'record.example.com',        // name
            '',                         // empty prio
            3600,                       // ttl
            86400                       // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('valid.content.example.com', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['prio']);
    }

    /**
     * PowerDNS answers SERVFAIL for these and aborts AXFR of the whole zone.
     */
    #[DataProvider('malformedGenericContentProvider')]
    public function testValidateRejectsMalformedGenericContent(string $content): void
    {
        $result = $this->validator->validate($content, 'record.example.com', '', 3600, 86400);

        $this->assertFalse($result->isValid());
    }

    public static function malformedGenericContentProvider(): array
    {
        return [
            'not hex' => ['\# 2 zz'],
            'length larger than data' => ['\# 5 00'],
            'length smaller than data' => ['\# 1 abcd'],
            'odd hex digits' => ['\# 1 abc'],
            'missing length' => ['\# abcd'],
            'data with length 0' => ['\# 0 ab'],
            'leading whitespace' => [' \# 2 zz'],
            'length above 16 bits' => ['\# 65536 ' . str_repeat('00', 65536)],
        ];
    }

    #[DataProvider('validGenericContentProvider')]
    public function testValidateAcceptsWellFormedGenericContent(string $content): void
    {
        $result = $this->validator->validate($content, 'record.example.com', '', 3600, 86400);

        $this->assertTrue($result->isValid());
    }

    public static function validGenericContentProvider(): array
    {
        return [
            'single chunk' => ['\# 2 abcd'],
            'split by spaces' => ['\# 4 c0 00 02 01'],
            'upper case hex' => ['\# 2 ABCD'],
            'empty data' => ['\# 0'],
        ];
    }

    public function testANumberedTypeOnlyAcceptsGenericData(): void
    {
        $validator = new DefaultRecordValidator('TYPE65280');

        $this->assertFalse($validator->validate('abcd', 'record.example.com', '', 3600, 86400)->isValid());
        $this->assertTrue($validator->validate('\# 2 abcd', 'record.example.com', '', 3600, 86400)->isValid());
        // Another unlisted type name keeps the old leniency for its own syntax
        $this->assertTrue((new DefaultRecordValidator('CUSTOM'))->validate('abcd', 'record.example.com', '', 3600, 86400)->isValid());
    }
}
