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

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Validation\ValidationResult;

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
}
