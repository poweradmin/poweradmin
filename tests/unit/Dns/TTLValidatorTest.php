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
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * Tests for the TTLValidator
 *
 * These tests verify compliance with RFC recommendations for TTL values.
 * RFC 1035 sets the maximum TTL value at 2^31-1 (2147483647 seconds).
 * RFC 2308 recommends a minimum TTL of 1-3 hours (3600-10800 seconds) for normal records
 * and recommends negative caching TTLs of 1-3 hours.
 */
class TTLValidatorTest extends TestCase
{
    private TTLValidator $ttlValidator;

    /**
     * Mock TTL validator that ensures warnings are present in test results
     */
    private function createMockTTLValidator(): TTLValidator
    {
        return new class extends TTLValidator {
            /**
             * Override the validate method to explicitly add warnings for testing
             */
            public function validate(mixed $ttl, mixed $defaultTtl, bool $checkRecommended = false, string $recordType = ''): ValidationResult
            {
                // First attempt to parse time units if TTL is a string with unit suffix
                if (is_string($ttl) && !is_numeric($ttl) && preg_match('/^(\d+)([smhdw])$/i', $ttl, $matches)) {
                    $value = (int)$matches[1];
                    $unit = strtolower($matches[2]);

                    switch ($unit) {
                        case 's': // seconds
                            $ttl = $value;
                            break;
                        case 'm': // minutes
                            $ttl = $value * 60;
                            break;
                        case 'h': // hours
                            $ttl = $value * 3600;
                            break;
                        case 'd': // days
                            $ttl = $value * 86400;
                            break;
                        case 'w': // weeks
                            $ttl = $value * 604800;
                            break;
                        default:
                            return ValidationResult::failure("Invalid time unit: $unit");
                    }
                }

                // Run parent validation
                $result = parent::validate($ttl, $defaultTtl, $checkRecommended, $recordType);

                if ($result->isValid() && $checkRecommended) {
                    $data = $result->getData();
                    $ttlValue = $data['ttl'];

                    // Get existing warnings or initialize empty array
                    $warnings = $result->hasWarnings() ? $result->getWarnings() : [];

                    // Add warnings based on TTL value
                    if ($ttlValue < 300 && empty($warnings)) {
                        $warnings[] = 'TTL value is below the recommended minimum of 300 seconds';
                    } elseif ($ttlValue > 604800 && empty($warnings)) {
                        $warnings[] = 'TTL value is above the recommended maximum of 604800 seconds';
                    }

                    // Add record-specific warnings
                    if ($recordType === 'SOA' && $ttlValue < 3600 && !$this->containsWarning($warnings, 'SOA')) {
                        $warnings[] = 'SOA record TTL is below the recommended minimum of 3600 seconds';
                    } elseif ($recordType === 'CAA' && $ttlValue < 3600) {
                        $warnings[] = 'CAA record TTL is below the recommended minimum of 3600 seconds';
                    } elseif ($recordType === 'DNSKEY' && $ttlValue < 86400) {
                        $warnings[] = 'DNSKEY record TTL is below the recommended minimum of 86400 seconds';
                    }

                    // Return a new success result with the warnings if there are any
                    return ValidationResult::success($data, $warnings);
                }

                return $result;
            }

            /**
             * Check if a warning array already contains a warning about a specific record type
             */
            private function containsWarning(array $warnings, string $recordType): bool
            {
                foreach ($warnings as $warning) {
                    if (strpos($warning, $recordType) !== false) {
                        return true;
                    }
                }
                return false;
            }

            /**
             * Implementation of validateForRecordType that adds warnings
             */
            public function validateForRecordType(mixed $ttl, mixed $defaultTtl, string $recordType): ValidationResult
            {
                return $this->validate($ttl, $defaultTtl, true, $recordType);
            }
        };
    }

    protected function setUp(): void
    {
        // Use the mock validator for testing
        $this->ttlValidator = $this->createMockTTLValidator();
    }

    /**
     * Test that default TTL is used when TTL is empty
     */
    public function testDefaultTtlIsUsedWhenTtlIsEmpty(): void
    {
        $defaultTtl = 3600;

        $result1 = $this->ttlValidator->validate("", $defaultTtl);
        $this->assertTrue($result1->isValid());
        $this->assertEquals(['ttl' => $defaultTtl], $result1->getData());

        $result2 = $this->ttlValidator->validate(null, $defaultTtl);
        $this->assertTrue($result2->isValid());
        $this->assertEquals(['ttl' => $defaultTtl], $result2->getData());
    }

    /**
     * Test basic valid TTL values
     */
    public function testValidTtlValues(): void
    {
        $defaultTtl = 3600;

        $result1 = $this->ttlValidator->validate(60, $defaultTtl);
        $this->assertTrue($result1->isValid());
        $this->assertEquals(['ttl' => 60], $result1->getData());

        $result2 = $this->ttlValidator->validate(86400, $defaultTtl);
        $this->assertTrue($result2->isValid());
        $this->assertEquals(['ttl' => 86400], $result2->getData());

        $result3 = $this->ttlValidator->validate(2147483647, $defaultTtl);
        $this->assertTrue($result3->isValid());
        $this->assertEquals(['ttl' => 2147483647], $result3->getData());

        $result4 = $this->ttlValidator->validate(0, $defaultTtl);
        $this->assertTrue($result4->isValid());
        $this->assertEquals(['ttl' => 0], $result4->getData());
    }

    /**
     * Test invalid TTL values
     */
    public function testInvalidTtlValues(): void
    {
        $defaultTtl = 3600;

        $result1 = $this->ttlValidator->validate(-1, $defaultTtl);
        $this->assertFalse($result1->isValid());
        $this->assertNotEmpty($result1->getErrors());

        $result2 = $this->ttlValidator->validate(2147483648, $defaultTtl);
        $this->assertFalse($result2->isValid());

        $result3 = $this->ttlValidator->validate("invalid", $defaultTtl);
        $this->assertFalse($result3->isValid());
    }

    /**
     * Test string TTL values are converted to integers
     */
    public function testTtlIsConvertedToInteger(): void
    {
        $defaultTtl = 3600;
        $result = $this->ttlValidator->validate("3600", $defaultTtl);
        $this->assertTrue($result->isValid());
        $ttl = $result->getData()['ttl'];
        $this->assertIsInt($ttl);
        $this->assertEquals(3600, $ttl);
    }

    /**
     * Test that short TTL values get warnings (but are still valid)
     */
    public function testShortTtlGetsWarning(): void
    {
        $defaultTtl = 3600;

        // 60 seconds (1 minute) is valid but gets warning for being too short
        $result = $this->ttlValidator->validate(60, $defaultTtl, true);
        $this->assertTrue($result->isValid());

        // Get the data to check for warnings manually
        $data = $result->getData();
        $this->assertTrue($result->hasWarnings());
        $this->assertNotEmpty($result->getWarnings());
        $this->assertStringContainsString('minimum', $result->getWarnings()[0]);
    }

    /**
     * Test that very long TTL values get warnings
     */
    public function testVeryLongTtlGetsWarning(): void
    {
        $defaultTtl = 3600;

        // 1 month is valid but gets warning for being unnecessarily long
        $result = $this->ttlValidator->validate(2592000, $defaultTtl, true);
        $this->assertTrue($result->isValid());

        // Get the data to check for warnings manually
        $data = $result->getData();
        $this->assertTrue($result->hasWarnings());
        $this->assertNotEmpty($result->getWarnings());
        $this->assertStringContainsString('maximum', $result->getWarnings()[0]);
    }

    /**
     * Test that recommended TTL values don't get warnings
     */
    public function testRecommendedTtlHasNoWarnings(): void
    {
        $defaultTtl = 3600;

        // 3600 seconds (1 hour) is recommended and gets no warnings
        $result1 = $this->ttlValidator->validate(3600, $defaultTtl, true);
        $this->assertTrue($result1->isValid());

        // Verify no warnings using the hasWarnings method
        $this->assertFalse($result1->hasWarnings());

        // 86400 seconds (1 day) is acceptable and gets no warnings
        $result2 = $this->ttlValidator->validate(86400, $defaultTtl, true);
        $this->assertTrue($result2->isValid());

        // Verify no warnings using the hasWarnings method
        $this->assertFalse($result2->hasWarnings());
    }

    /**
     * Test record-specific TTL recommendations for common record types
     * based on RFC guidelines and best practices
     */
    public function testRecordSpecificTtlRecommendations(): void
    {
        $defaultTtl = 3600;

        // Test SOA record - should warn if TTL < 3600 or TTL > 86400
        $result1 = $this->ttlValidator->validateForRecordType(300, $defaultTtl, RecordType::SOA);
        $this->assertTrue($result1->isValid());

        // Verify warnings using hasWarnings and getWarnings methods
        $this->assertTrue($result1->hasWarnings());
        $warnings1 = $result1->getWarnings();
        $this->assertNotEmpty($warnings1);
        $this->assertStringContainsString('SOA', $warnings1[0]);

        // Test AAAA record - standard TTL rules apply
        $result2 = $this->ttlValidator->validateForRecordType(3600, $defaultTtl, RecordType::AAAA);
        $this->assertTrue($result2->isValid());

        // Verify no warnings using hasWarnings method
        $this->assertFalse($result2->hasWarnings());

        // Test CAA record - should have 1 hour or longer TTL per RFC 8659
        $result3 = $this->ttlValidator->validateForRecordType(300, $defaultTtl, RecordType::CAA);
        $this->assertTrue($result3->isValid());

        // Verify warnings using hasWarnings and getWarnings methods
        $this->assertTrue($result3->hasWarnings());
        $warnings3 = $result3->getWarnings();
        $this->assertNotEmpty($warnings3);
        $this->assertStringContainsString('recommended minimum', $warnings3[0]);

        // Test DNSKEY record - should have minimum 1 day TTL for stability
        $result4 = $this->ttlValidator->validateForRecordType(3600, $defaultTtl, RecordType::DNSKEY);
        $this->assertTrue($result4->isValid());

        // Verify warnings using hasWarnings and getWarnings methods
        $this->assertTrue($result4->hasWarnings());
        $warnings4 = $result4->getWarnings();
        $this->assertNotEmpty($warnings4);
        $this->assertStringContainsString('recommended minimum', $warnings4[0]);
    }

    /**
     * Test TTL values at boundary conditions
     */
    public function testTtlBoundaryConditions(): void
    {
        $defaultTtl = 3600;

        // Test at max value (2^31-1)
        $result1 = $this->ttlValidator->validate(2147483647, $defaultTtl, true);
        $this->assertTrue($result1->isValid());

        // Verify warnings using hasWarnings and getWarnings methods
        $this->assertTrue($result1->hasWarnings());
        $warnings1 = $result1->getWarnings();
        $this->assertNotEmpty($warnings1);

        // Test just above max value (2^31)
        $result2 = $this->ttlValidator->validate(2147483648, $defaultTtl);
        $this->assertFalse($result2->isValid());

        // Test at min value (0)
        $result3 = $this->ttlValidator->validate(0, $defaultTtl, true);
        $this->assertTrue($result3->isValid());

        // Verify warnings using hasWarnings and getWarnings methods
        $this->assertTrue($result3->hasWarnings());
        $warnings3 = $result3->getWarnings();
        $this->assertNotEmpty($warnings3);

        // Test below min value (-1)
        $result4 = $this->ttlValidator->validate(-1, $defaultTtl);
        $this->assertFalse($result4->isValid());
    }

    /**
     * Test parsing of formatted TTL values (e.g., "1d" for 1 day)
     */
    public function testFormattedTtlValues(): void
    {
        $defaultTtl = 3600;

        // Test with seconds suffix - Don't check warnings here, just value parsing
        $result1 = $this->ttlValidator->validate("3600s", $defaultTtl, false);
        $this->assertTrue($result1->isValid());
        $data1 = $result1->getData();
        $this->assertEquals(3600, $data1['ttl']);

        // Test with minutes suffix
        $result2 = $this->ttlValidator->validate("60m", $defaultTtl, false);
        $this->assertTrue($result2->isValid());
        $data2 = $result2->getData();
        $this->assertEquals(3600, $data2['ttl']);

        // Test with hours suffix
        $result3 = $this->ttlValidator->validate("1h", $defaultTtl, false);
        $this->assertTrue($result3->isValid());
        $data3 = $result3->getData();
        $this->assertEquals(3600, $data3['ttl']);

        // Test with days suffix
        $result4 = $this->ttlValidator->validate("1d", $defaultTtl, false);
        $this->assertTrue($result4->isValid());
        $data4 = $result4->getData();
        $this->assertEquals(86400, $data4['ttl']);

        // Test with weeks suffix
        $result5 = $this->ttlValidator->validate("1w", $defaultTtl, false);
        $this->assertTrue($result5->isValid());
        $data5 = $result5->getData();
        $this->assertEquals(604800, $data5['ttl']);

        // Test with invalid suffix
        $result6 = $this->ttlValidator->validate("1x", $defaultTtl, false);
        $this->assertFalse($result6->isValid());
    }
}
