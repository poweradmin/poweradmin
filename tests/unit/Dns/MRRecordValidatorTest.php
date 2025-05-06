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

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\MRRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class MRRecordValidatorTest extends TestCase
{
    private MRRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->validator = new MRRecordValidator($this->configMock);
    }

    /**
     * Test validation with valid domain name
     */
    public function testValidateWithValidDomainName(): void
    {
        $result = $this->validator->validate(
            'new-mailbox.example.com',  // content
            'old-mailbox.example.com',  // name
            '',                         // prio (empty as not used for MR)
            3600,                       // ttl
            86400                       // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('new-mailbox.example.com', $data['content']);
        $this->assertEquals(3600, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
    }

    /**
     * Test validation with empty content
     */
    public function testValidateWithEmptyContent(): void
    {
        $result = $this->validator->validate(
            '',                         // empty content
            'old-mailbox.example.com',  // name
            '',                         // prio
            3600,                       // ttl
            86400                       // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('empty', $result->getFirstError());
    }

    /**
     * Test validation with invalid domain name
     */
    public function testValidateWithInvalidDomainName(): void
    {
        $result = $this->validator->validate(
            'invalid..domain',          // invalid content
            'old-mailbox.example.com',  // name
            '',                         // prio
            3600,                       // ttl
            86400                       // defaultTTL
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('domain name', $result->getFirstError());
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTtl(): void
    {
        $result = $this->validator->validate(
            'new-mailbox.example.com',  // content
            'old-mailbox.example.com',  // name
            '',                         // prio
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
            'new-mailbox.example.com',  // content
            'old-mailbox.example.com',  // name
            '',                         // prio
            '',                         // empty ttl, should use default
            86400                       // defaultTTL
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('new-mailbox.example.com', $data['content']);
        $this->assertEquals(86400, $data['ttl']);
        $this->assertEquals(0, $data['priority']);
    }
}
