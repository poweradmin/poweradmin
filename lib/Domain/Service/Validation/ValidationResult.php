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
    private $data;

    /**
     * Private constructor - use factory methods
     */
    private function __construct(bool $isValid, array $errors = [], $data = null)
    {
        $this->isValid = $isValid;
        $this->errors = $errors;
        $this->data = $data;
    }

    /**
     * Create a successful validation result
     */
    public static function success($data): self
    {
        return new self(true, [], $data);
    }

    /**
     * Create a failed validation result
     */
    public static function failure($errors): self
    {
        $errorArray = is_array($errors) ? $errors : [$errors];
        return new self(false, $errorArray);
    }

    /**
     * Create a failed validation result with multiple errors
     */
    public static function errors(array $errors): self
    {
        return new self(false, $errors);
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
}
