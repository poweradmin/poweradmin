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

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DMARCRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the DMARCRecordValidator
 *
 * This test suite verifies that the DMARC record validator correctly validates
 * records according to RFC 7489.
 */
class DMARCRecordValidatorTest extends TestCase
{
    private DMARCRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new DMARCRecordValidator($this->configMock);
    }

    /**
     * Test validation of a basic valid DMARC record
     */
    public function testValidateWithBasicValidRecord()
    {
        // Basic DMARC record with required tags only (v and p)
        $content = '"v=DMARC1; p=none;"';
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']); // DMARC always uses 0 priority
        $this->assertEquals(3600, $data['ttl']);
    }

    /**
     * Test validation of a comprehensive DMARC record with all tags
     */
    public function testValidateWithFullDmarcRecord()
    {
        // Full DMARC record with all possible tags as per RFC 7489
        $content = '"v=DMARC1; p=reject; sp=quarantine; adkim=s; aspf=r; fo=1:s:d; pct=100; rf=afrf; ri=86400; rua=mailto:dmarc-reports@example.com; ruf=mailto:forensic@example.com;"';
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation of a DMARC record with multiple reporting URIs
     */
    public function testValidateWithMultipleReportingUris()
    {
        $content = '"v=DMARC1; p=reject; rua=mailto:reports@example.com,mailto:reports@thirdparty.example.net; ruf=mailto:forensic@example.com;"';
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with missing required 'v' tag
     */
    public function testValidateWithMissingVersionTag()
    {
        $content = '"p=none;"'; // Missing required v=DMARC1 tag
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('v=DMARC1', $result->getFirstError());
    }

    /**
     * Test validation with missing required 'p' tag
     */
    public function testValidateWithMissingPolicyTag()
    {
        $content = '"v=DMARC1;"'; // Missing required p tag
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        // The implementation uses 'tag missing: p' rather than 'p tag'
        $this->assertStringContainsString('p', $result->getFirstError());
    }

    /**
     * Test validation with incorrect version value
     */
    public function testValidateWithIncorrectVersionValue()
    {
        $content = '"v=DMARC2; p=none;"'; // Wrong version, must be DMARC1
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('DMARC1', $result->getFirstError());
    }

    /**
     * Test validation with incorrect policy value
     */
    public function testValidateWithIncorrectPolicyValue()
    {
        $content = '"v=DMARC1; p=invalid;"'; // Invalid policy value
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('policy', $result->getFirstError());
    }

    /**
     * Test validation with incorrect subdomain policy value
     */
    public function testValidateWithIncorrectSubdomainPolicyValue()
    {
        $content = '"v=DMARC1; p=none; sp=invalid;"'; // Invalid subdomain policy value
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('subdomain policy', $result->getFirstError());
    }

    /**
     * Test validation with incorrect DKIM alignment mode
     */
    public function testValidateWithIncorrectDkimAlignmentMode()
    {
        $content = '"v=DMARC1; p=none; adkim=x;"'; // Invalid DKIM alignment mode
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('DKIM alignment', $result->getFirstError());
    }

    /**
     * Test validation with incorrect SPF alignment mode
     */
    public function testValidateWithIncorrectSpfAlignmentMode()
    {
        $content = '"v=DMARC1; p=none; aspf=x;"'; // Invalid SPF alignment mode
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('SPF alignment', $result->getFirstError());
    }

    /**
     * Test validation with invalid failure reporting options
     */
    public function testValidateWithInvalidFailureReportingOptions()
    {
        $content = '"v=DMARC1; p=none; fo=x;"'; // Invalid failure reporting option
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('failure reporting', $result->getFirstError());
    }

    /**
     * Test validation with invalid percentage
     */
    public function testValidateWithInvalidPercentage()
    {
        $content = '"v=DMARC1; p=none; pct=101;"'; // Invalid percentage (> 100)
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('percentage', $result->getFirstError());
    }

    /**
     * Test validation with invalid report format
     */
    public function testValidateWithInvalidReportFormat()
    {
        $content = '"v=DMARC1; p=none; rf=invalid;"'; // Invalid report format
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('report format', $result->getFirstError());
    }

    /**
     * Test validation with invalid report interval
     */
    public function testValidateWithInvalidReportInterval()
    {
        $content = '"v=DMARC1; p=none; ri=abc;"'; // Invalid report interval (not a number)
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        // The implementation uses 'reporting interval tag (ri)' rather than 'report interval'
        $this->assertStringContainsString('ri', $result->getFirstError());
    }

    /**
     * Test validation with invalid aggregate report URI
     */
    public function testValidateWithInvalidAggregateReportUri()
    {
        $content = '"v=DMARC1; p=none; rua=invalid-uri;"'; // Invalid aggregate report URI
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        // The implementation uses 'rua tag must contain valid mailto:' rather than 'aggregate report URI'
        $this->assertStringContainsString('rua', $result->getFirstError());
    }

    /**
     * Test validation with invalid forensic report URI
     */
    public function testValidateWithInvalidForensicReportUri()
    {
        $content = '"v=DMARC1; p=none; ruf=invalid-uri;"'; // Invalid forensic report URI
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        // The implementation uses 'ruf tag must contain valid mailto:' rather than 'forensic report URI'
        $this->assertStringContainsString('ruf', $result->getFirstError());
    }

    /**
     * Test validation with invalid record name (not starting with _dmarc)
     */
    public function testValidateWithInvalidRecordName()
    {
        $content = '"v=DMARC1; p=none;"';
        $name = 'dmarc.example.com'; // Missing leading underscore
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('_dmarc', $result->getFirstError());
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTTL()
    {
        $content = '"v=DMARC1; p=none;"';
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    /**
     * Test validation with non-zero priority
     */
    public function testValidateWithNonZeroPriority()
    {
        $content = '"v=DMARC1; p=none;"';
        $name = '_dmarc.example.com';
        $prio = 10; // Non-zero priority (invalid for DMARC)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }

    /**
     * Test validation of organizational domain DMARC record
     */
    public function testValidateWithOrganizationalDomain()
    {
        $content = '"v=DMARC1; p=none;"';
        $name = '_dmarc.example.com'; // Organizational domain DMARC record
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation of subdomain DMARC record
     */
    public function testValidateWithSubdomain()
    {
        $content = '"v=DMARC1; p=none;"';
        $name = '_dmarc.sub.example.com'; // Subdomain DMARC record
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }
}
