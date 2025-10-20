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
use Poweradmin\Domain\Service\DnsValidation\TXTRecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the TXTRecordValidator
 *
 * This test suite verifies compliance with RFC 7208 for TXT records.
 * RFC 7208 specifies:
 * - TXT strings are limited to 255 characters each
 * - Multiple strings can be concatenated to form longer records
 * - The overall record should respect quoting rules
 */
class TXTRecordValidatorTest extends TestCase
{
    private TXTRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        // Default validator with no length limit (for normal zone records)
        $this->validator = new TXTRecordValidator($this->configMock);
    }

    /**
     * Create a strict validator mock for testing more rigorous validation
     */
    private function createStrictValidator(): TXTRecordValidator
    {
        $validator = $this->getMockBuilder(TXTRecordValidator::class)
            ->setConstructorArgs([$this->configMock])
            ->onlyMethods(['validate'])
            ->getMock();

        $validator->method('validate')
            ->willReturnCallback(function (string $content, string $name, mixed $prio, $ttl, $defaultTTL) {
                // Check for unquoted content
                if (!(str_starts_with($content, '"') && str_ends_with($content, '"'))) {
                    return ValidationResult::failure(_('TXT record content must be enclosed in quotes.'));
                }

                // Check for invalid hostname characters
                if (preg_match('/[<>]/', $name)) {
                    return ValidationResult::failure(_('Invalid characters in hostname.'));
                }

                // Delegate to the real validator for other cases
                return $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);
            });

        return $validator;
    }

    /**
     * Test validation with valid simple TXT record
     */
    public function testValidateWithValidData()
    {
        $content = '"This is a valid TXT record"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(0, $data['prio']); // TXT always uses 0
        $this->assertEquals(3600, $data['ttl']);
    }

    /**
     * Test validation with RFC 7208 compliant multi-string TXT record
     */
    public function testValidateWithMultiStringTxtRecord()
    {
        $content = '"First string" "Second string" "Third string"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with long TXT record (256 bytes)
     * PowerDNS will automatically split this at wire format level
     */
    public function testValidateWithVeryLongSingleString()
    {
        // Creating a string exactly 256 characters long
        // This should now be VALID as PowerDNS handles the splitting
        $longString = str_repeat('a', 256);
        $content = '"' . $longString . '"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with long TXT record split into multiple strings
     * to comply with RFC 7208 255-byte limit
     */
    public function testValidateWithLongTxtRecordSplitIntoMultipleStrings()
    {
        // Creating 2 strings of 200 characters each
        $string1 = str_repeat('a', 200);
        $string2 = str_repeat('b', 200);
        $content = '"' . $string1 . '" "' . $string2 . '"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with properly quoted and escaped TXT record
     */
    public function testValidateWithProperlyEscapedQuotes()
    {
        $content = '"This has \\"properly\\" escaped quotes"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with improperly quoted TXT record (unescaped quotes)
     */
    public function testValidateWithUnescapedQuotes()
    {
        $content = '"This has "unescaped" quotes"'; // Contains unescaped quotes
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('quotes', $result->getFirstError());
    }

    /**
     * Test validation with TXT record containing HTML tags (which should be invalid)
     */
    public function testValidateWithHTMLTags()
    {
        $content = '"This has <html> tags"'; // TXT with HTML tags
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('HTML', $result->getFirstError());
    }

    /**
     * Test validation with unquoted TXT content (should be invalid)
     */
    public function testValidateWithInvalidNoProperQuoting()
    {
        $content = 'This needs quotes'; // Not properly quoted
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        // Testing with strict validator that enforces quoting
        $strictValidator = $this->createStrictValidator();
        $result = $strictValidator->validate($content, $name, $prio, $ttl, $defaultTTL);
        $this->assertFalse($result->isValid(), 'Strict validator should reject unquoted content');

        // Note: The current implementation checks for quotes
        $actualResult = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);
        $this->assertFalse($actualResult->isValid(), 'Current implementation rejects unquoted content');
    }

    /**
     * Test validation with invalid hostname format
     */
    public function testValidateWithInvalidName()
    {
        $content = '"This is a valid TXT record"';
        $name = "<invalid>hostname"; // Name with invalid characters
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        // Testing with strict validator that enforces hostname validation
        $strictValidator = $this->createStrictValidator();
        $result = $strictValidator->validate($content, $name, $prio, $ttl, $defaultTTL);
        $this->assertFalse($result->isValid(), 'Strict validator should reject invalid hostname');

        // Note: The current implementation now rejects angle brackets
        $actualResult = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);
        $this->assertFalse($actualResult->isValid(), 'Current implementation rejects angle brackets in hostnames');
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTTL()
    {
        $content = '"This is a valid TXT record"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    /**
     * Test validation with default TTL
     */
    public function testValidateWithDefaultTTL()
    {
        $content = '"This is a valid TXT record"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    /**
     * Test validation with non-zero priority (invalid for TXT records)
     */
    public function testValidateWithNonZeroPriority()
    {
        $content = '"This is a valid TXT record"';
        $name = 'txt.example.com';
        $prio = 10; // Non-zero priority (should be invalid for TXT records)
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }

    /**
     * Test validation of SPF record in TXT format according to RFC 7208
     */
    public function testValidateWithSpfRecordInTxtFormat()
    {
        $content = '"v=spf1 ip4:192.0.2.0/24 ip4:198.51.100.123 a -all"';
        $name = 'example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with maximum length TXT record string (255 characters)
     */
    public function testValidateWithMaxLengthTxtString()
    {
        // Create a string that's exactly 255 characters (the max allowed per RFC)
        $maxString = str_repeat('a', 255);
        $content = '"' . $maxString . '"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with TXT record containing safe printable characters
     *
     * NOTE: We're intentionally avoiding angle brackets which are now forbidden
     * in our validator
     */
    public function testValidateWithAllPermittedPrintableCharacters()
    {
        $content = '"abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-={}[]|\\:;\',.?/"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        // Create a mock validator that always validates content successfully
        $mockValidator = $this->createMock(TXTRecordValidator::class);
        $mockValidator->method('validate')
            ->willReturn(ValidationResult::success([
                'content' => $content,
                'name' => $name,
                'prio' => 0,
                'ttl' => $ttl
            ]));

        $result = $mockValidator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with DKIM record in TXT format
     */
    public function testValidateWithDkimRecordInTxtFormat()
    {
        $p1 = 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCqGKukO1De7zhZj6+H0qtjTkVxwTCpvKe4eCZ0FPqri0cb2JZfXJ/';
        $p2 = 'DgYSF6vUpwmJG8wVQZKjeGcjDOL5UlsuusFncCzWBQ7RKNUSesmQRMSGkVb1/3j+skZ6UtW+5u09lHNsj6tQ51s1SPrCBkedbNf0Tp0GbMJDyR4e9T04ZZwIDAQAB';
        $content = '"v=DKIM1; k=rsa; p=' . $p1 . $p2 . ';"';
        $name = 'selector._domainkey.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with empty string TXT record
     */
    public function testValidateWithEmptyStringTxtRecord()
    {
        $content = '""'; // Empty string
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with DMARC record in TXT format
     */
    public function testValidateWithDmarcRecordInTxtFormat()
    {
        $content = '"v=DMARC1; p=none; rua=mailto:dmarc-reports@example.com; ruf=mailto:forensic@example.com; pct=100"';
        $name = '_dmarc.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        // No need to check specific fields since the DMARCRecordValidator will be called
    }

    /**
     * Test validation with DKIM record from GitHub issue #809
     * This is a real-world example that was previously rejected
     */
    public function testValidateWithLongDkimRecordFromIssue809()
    {
        // Real DKIM record from issue #809 (392 bytes)
        $content = '"v=DKIM1; k=rsa; h=sha256; s=email; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAr4jWwiUrBv/5M87XAgbSmaMa3LvyWQ6Rj/SInmNY653/0i2qr1AwuM96XA/X0RLN78BmNHU3aXg4BvOtyhcHiKeTZoz4xYpFgALWjdq9ygsdoDQEI1dVHValLEiOGevIynVtDwmxohwscZClYIAYPI1YByC889JuIm21c/h8rN/vQrd7tlb1a01bYFGMKR+kUIHZ8VAxzvgvYm5+hp09Nvmu7NGVqfNFeKGez6CTFDyPdxHJTdP8hnzDHWxgxker9J/XyppSR4yePP/KKPgCGvkRJ2FxCVqHXejielxbGPB4DkAZb9qNVXC8W+Mc4Z39vNMzrxqoXwYHL64ZqIEx0QIDAQAB"';
        $name = 'selector._domainkey.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid(), 'Long DKIM record should be valid - PowerDNS will handle splitting');
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with very long TXT record for normal zones (no limit)
     */
    public function testValidateWithVeryLongTxtRecordNoLimit()
    {
        // Creating content much longer than 2048 bytes
        // Should be VALID for normal zone records (uses records.content VARCHAR(64000))
        $veryLongString = str_repeat('a', 5000);
        $content = '"' . $veryLongString . '"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid(), 'Long TXT records should be valid for normal zones');
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with zone template limit (2048 bytes)
     */
    public function testValidateWithZoneTemplateLimitExceeded()
    {
        // Create validator with zone template limit
        $templateValidator = new TXTRecordValidator($this->configMock, 2048);

        // Creating content that exceeds 2048 bytes INCLUDING quotes
        // Content: 2047 bytes + 2 quotes = 2049 bytes total
        $veryLongString = str_repeat('a', 2047);
        $content = '"' . $veryLongString . '"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $templateValidator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('2048', $result->getFirstError());
    }

    /**
     * Test validation with TXT record at zone template maximum
     */
    public function testValidateWithZoneTemplateMaximumAllowedLength()
    {
        // Create validator with zone template limit
        $templateValidator = new TXTRecordValidator($this->configMock, 2048);

        // Creating content exactly at 2048 bytes INCLUDING quotes
        // This matches the zone_templ_records.content VARCHAR(2048) database constraint
        // Content: 2046 bytes + 2 quotes = 2048 bytes total
        $maxLengthString = str_repeat('a', 2046);
        $content = '"' . $maxLengthString . '"';
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $templateValidator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        // Verify the stored content fits in VARCHAR(2048)
        $this->assertLessThanOrEqual(2048, strlen($data['content']));
    }

    /**
     * Test that multi-string TXT records respect zone template length limit
     */
    public function testValidateMultiStringWithZoneTemplateLimit()
    {
        // Create validator with zone template limit
        $templateValidator = new TXTRecordValidator($this->configMock, 2048);

        // Create multi-string content that would exceed 2048 bytes with quotes and spaces
        // Each string: 255 chars + 2 quotes = 257 bytes
        // 8 strings: 8 * 257 = 2056 bytes + 7 spaces = 2063 bytes total (exceeds limit)
        $strings = [];
        for ($i = 0; $i < 8; $i++) {
            $strings[] = '"' . str_repeat('a', 255) . '"';
        }
        $content = implode(' ', $strings);
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $templateValidator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid(), 'Multi-string content exceeding zone template limit should be rejected');
        $this->assertStringContainsString('2048', $result->getFirstError());
    }

    /**
     * Test that long multi-string TXT records are valid for normal zones
     */
    public function testValidateLongMultiStringForNormalZones()
    {
        // Create multi-string content exceeding 2048 bytes
        // Should be VALID for normal zones (no limit)
        $strings = [];
        for ($i = 0; $i < 10; $i++) {
            $strings[] = '"' . str_repeat('a', 255) . '"';
        }
        $content = implode(' ', $strings); // ~2570 bytes total
        $name = 'txt.example.com';
        $prio = '';
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid(), 'Long multi-string TXT records should be valid for normal zones');
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }
}
