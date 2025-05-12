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

namespace Poweradmin\Domain\Service\Validation;

use RuntimeException;

/**
 * Simple validation result class following the Result pattern
 *
 * @package Poweradmin\Domain\Service\Validation
 * @copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright 2010-2025 Poweradmin Development Team
 * @license https://opensource.org/licenses/GPL-3.0 GPL
 */
final class ValidationResult
{
    private bool $isValid;
    private array $errors = [];
    private array $warnings = [];
    private $data;

    /**
     * Private constructor - use factory methods
     */
    private function __construct(bool $isValid, array $errors = [], array $warnings = [], $data = null)
    {
        $this->isValid = $isValid;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->data = $data;
    }

    /**
     * Create a successful validation result
     *
     * @param mixed $data The validated data
     * @param array $warnings Optional warning messages for valid data
     */
    public static function success($data, array $warnings = []): self
    {
        // Extract warnings from data if in array format for backward compatibility
        $extractedWarnings = [];
        if (is_array($data) && isset($data['warnings']) && is_array($data['warnings'])) {
            $extractedWarnings = $data['warnings'];

            // For backward compatibility, don't remove warnings from data struct
            // Many validators expect ['warnings'] to stay in the data
        }

        // Merge explicitly provided warnings with extracted ones
        $allWarnings = array_merge($extractedWarnings, $warnings);

        return new self(true, [], $allWarnings, $data);
    }

    /**
     * Create a failed validation result
     *
     * @param string|array $errors Error message or array of error messages
     * @param array $warnings Optional warning messages for invalid data
     */
    public static function failure($errors, array $warnings = []): self
    {
        $errorArray = is_array($errors) ? $errors : [$errors];
        return new self(false, $errorArray, $warnings);
    }

    /**
     * Create a failed validation result with multiple errors
     *
     * @param array $errors Array of error messages
     * @param array $warnings Optional warning messages for invalid data
     */
    public static function errors(array $errors, array $warnings = []): self
    {
        return new self(false, $errors, $warnings);
    }

    /**
     * Check if validation succeeded
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * Get all validation error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get first error message or empty string if no errors
     */
    public function getFirstError(): string
    {
        return count($this->errors) > 0 ? $this->errors[0] : '';
    }

    /**
     * Get data from successful validation
     *
     * @throws RuntimeException if validation failed
     */
    public function getData()
    {
        if (!$this->isValid) {
            throw new RuntimeException('Cannot get data from failed validation result');
        }
        return $this->data;
    }

    /**
     * Check if the validation result has warnings
     *
     * @return bool True if the result has warnings
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    /**
     * Get warnings from the validation result
     *
     * @return array The warnings array or empty array if none exist
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Get first warning message or empty string if no warnings
     *
     * @return string The first warning message
     */
    public function getFirstWarning(): string
    {
        return count($this->warnings) > 0 ? $this->warnings[0] : '';
    }

    /**
     * Add a warning to the validation result
     *
     * @param string $warning Warning message to add
     * @return self This ValidationResult instance for method chaining
     */
    public function addWarning(string $warning): self
    {
        $this->warnings[] = $warning;
        return $this;
    }

    /**
     * Add multiple warnings to the validation result
     *
     * @param array $warnings Array of warning messages to add
     * @return self This ValidationResult instance for method chaining
     */
    public function addWarnings(array $warnings): self
    {
        $this->warnings = array_merge($this->warnings, $warnings);
        return $this;
    }
}
