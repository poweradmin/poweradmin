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

namespace Poweradmin\Test\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use RuntimeException;

/**
 * Tests for ValidationResult value object
 */
class ValidationResultTest extends TestCase
{
    /**
     * Test creating a success result
     */
    public function testSuccessResult(): void
    {
        $data = ['name' => 'example.com', 'content' => '192.168.1.1'];
        $result = ValidationResult::success($data);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
        $this->assertSame($data, $result->getData());
        $this->assertSame('', $result->getFirstError());
    }

    /**
     * Test creating a failure result with a single error
     */
    public function testFailureResultWithSingleError(): void
    {
        $error = 'Invalid hostname';
        $result = ValidationResult::failure($error);

        $this->assertFalse($result->isValid());
        $this->assertEquals([$error], $result->getErrors());
        $this->assertEquals($error, $result->getFirstError());
    }

    /**
     * Test creating a failure result with multiple errors
     */
    public function testFailureResultWithMultipleErrors(): void
    {
        $errors = ['Invalid hostname', 'Invalid IP address'];
        $result = ValidationResult::failure($errors);

        $this->assertFalse($result->isValid());
        $this->assertEquals($errors, $result->getErrors());
        $this->assertEquals($errors[0], $result->getFirstError());
    }

    /**
     * Test getting data from a failed validation
     */
    public function testGetDataFromFailedValidation(): void
    {
        $result = ValidationResult::failure('Error');

        $this->expectException(RuntimeException::class);
        $result->getData();
    }

    /**
     * Test full integration between validators and service
     */
    public function testValidationIntegration(): void
    {
        // Setup: Create a validator that validates an IP address
        $ipValidator = new class {
            public function validate(string $ip): ValidationResult
            {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return ValidationResult::success(['ip' => $ip]);
                }

                return ValidationResult::failure('Invalid IP address format');
            }
        };

        // Test valid data
        $validIp = '192.168.1.1';
        $validResult = $ipValidator->validate($validIp);
        $this->assertTrue($validResult->isValid());
        $this->assertEquals(['ip' => $validIp], $validResult->getData());

        // Test invalid data
        $invalidIp = 'not-an-ip';
        $invalidResult = $ipValidator->validate($invalidIp);
        $this->assertFalse($invalidResult->isValid());
        $this->assertEquals(['Invalid IP address format'], $invalidResult->getErrors());
    }

    // Legacy warning approach tests have been removed since we no longer support the old format

    /**
     * Test success result with explicit warnings parameter
     */
    public function testSuccessResultWithExplicitWarnings(): void
    {
        $data = ['ttl' => 300];
        $warnings = ['TTL value is below recommended minimum'];
        $result = ValidationResult::success($data, $warnings);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $this->assertEquals($warnings, $result->getWarnings());
        $this->assertEquals($data, $result->getData());
    }

    /**
     * Test success result without warnings
     */
    public function testSuccessResultWithoutWarnings(): void
    {
        $data = ['ttl' => 3600]; // No warnings
        $result = ValidationResult::success($data);

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->hasWarnings());
        $this->assertEmpty($result->getWarnings());
        $this->assertSame($data, $result->getData());
    }

    /**
     * Test getting warnings from a failed validation
     */
    public function testGetWarningsFromFailedValidation(): void
    {
        $result = ValidationResult::failure('Error');

        $this->assertFalse($result->hasWarnings());
        $this->assertEmpty($result->getWarnings());
    }

    /**
     * Test failure result with warnings
     */
    public function testFailureResultWithWarnings(): void
    {
        $error = 'Invalid hostname';
        $warnings = ['Input might be missing domain suffix'];
        $result = ValidationResult::failure($error, $warnings);

        $this->assertFalse($result->isValid());
        $this->assertEquals([$error], $result->getErrors());
        $this->assertTrue($result->hasWarnings());
        $this->assertEquals($warnings, $result->getWarnings());
    }

    /**
     * Test validation result with multiple warnings
     */
    public function testValidationResultWithMultipleWarnings(): void
    {
        $warnings = [
            'TTL value is below recommended minimum',
            'Non-standard port for HTTP service'
        ];
        $data = [
            'content' => '10 20 8080 server.example.com'
        ];
        $result = ValidationResult::success($data, $warnings);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $this->assertEquals($warnings, $result->getWarnings());
    }

    /**
     * Test full integration with warnings between validators and service
     */
    public function testValidationIntegrationWithWarnings(): void
    {
        // Setup: Create a validator that validates TTL values
        $ttlValidator = new class {
            public function validate(mixed $ttl, int $defaultTtl): ValidationResult
            {
                // Basic validation
                if (!is_numeric($ttl)) {
                    return ValidationResult::failure('TTL must be numeric');
                }

                $ttlValue = (int)$ttl;

                if ($ttlValue < 0 || $ttlValue > 2147483647) {
                    return ValidationResult::failure('TTL must be between 0 and 2147483647');
                }

                $warnings = [];

                // Add warnings for values outside recommended ranges
                if ($ttlValue < 300) {
                    $warnings[] = 'TTL value is below recommended minimum of 300 seconds';
                }

                if ($ttlValue > 604800) {
                    $warnings[] = 'TTL value is above recommended maximum of 604800 seconds (1 week)';
                }

                return ValidationResult::success(['ttl' => $ttlValue], $warnings);
            }
        };

        // Test valid TTL but with warning (too low)
        $lowTtl = 60;
        $lowTtlResult = $ttlValidator->validate($lowTtl, 3600);
        $this->assertTrue($lowTtlResult->isValid());
        $this->assertTrue($lowTtlResult->hasWarnings());
        $this->assertStringContainsString('below recommended minimum', $lowTtlResult->getWarnings()[0]);

        // Test valid TTL with no warnings (within recommended range)
        $goodTtl = 3600;
        $goodTtlResult = $ttlValidator->validate($goodTtl, 3600);
        $this->assertTrue($goodTtlResult->isValid());
        $this->assertFalse($goodTtlResult->hasWarnings());

        // Test invalid TTL (should have no warnings)
        $invalidTtl = 'not-numeric';
        $invalidTtlResult = $ttlValidator->validate($invalidTtl, 3600);
        $this->assertFalse($invalidTtlResult->isValid());
        $this->assertFalse($invalidTtlResult->hasWarnings());
    }

    /**
     * Test adding warnings using the addWarning method
     */
    public function testAddWarning(): void
    {
        $data = ['ttl' => 3600];
        $result = ValidationResult::success($data);
        $this->assertFalse($result->hasWarnings());

        // Add a warning
        $result->addWarning('Example warning message');
        $this->assertTrue($result->hasWarnings());
        $this->assertEquals(['Example warning message'], $result->getWarnings());
        $this->assertEquals('Example warning message', $result->getFirstWarning());

        // Add another warning
        $result->addWarning('Second warning message');
        $this->assertEquals([
            'Example warning message',
            'Second warning message'
        ], $result->getWarnings());
    }

    /**
     * Test adding multiple warnings using the addWarnings method
     */
    public function testAddWarnings(): void
    {
        $result = ValidationResult::failure('Error message');
        $this->assertFalse($result->hasWarnings());

        // Add multiple warnings
        $warningsToAdd = ['Warning 1', 'Warning 2', 'Warning 3'];
        $result->addWarnings($warningsToAdd);

        $this->assertTrue($result->hasWarnings());
        $this->assertEquals($warningsToAdd, $result->getWarnings());
        $this->assertEquals('Warning 1', $result->getFirstWarning());
    }

    /**
     * Test getFirstWarning method when no warnings exist
     */
    public function testGetFirstWarningWithNoWarnings(): void
    {
        $result = ValidationResult::success(true);
        $this->assertEquals('', $result->getFirstWarning());
    }

    /**
     * Test special case - warnings with empty data
     */
    public function testWarningsOnlyData(): void
    {
        $warnings = ['Just a warning'];
        $result = ValidationResult::success(null, $warnings);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $this->assertEquals(['Just a warning'], $result->getWarnings());
        $this->assertNull($result->getData());
    }
}
