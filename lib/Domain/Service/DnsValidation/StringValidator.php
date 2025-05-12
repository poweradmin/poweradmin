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

namespace Poweradmin\Domain\Service\DnsValidation;

use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * String validation for DNS records
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class StringValidator
{
    /**
     * Test if string is printable
     *
     * @param string $string string to validate
     * @return ValidationResult Validation result with error message if invalid
     */
    public static function validatePrintable(string $string): ValidationResult
    {
        if (!preg_match('/^[[:print:]]+$/', trim($string))) {
            return ValidationResult::failure(_('Invalid characters have been used in this record.'));
        }
        return ValidationResult::success($string);
    }

    /**
     * Test if string has html opening and closing tags
     *
     * @param string $string Input string
     * @return ValidationResult Validation result with error message if HTML tags found
     */
    public static function validateNoHtmlTags(string $string): ValidationResult
    {
        if (preg_match('/[<>]/', trim($string))) {
            return ValidationResult::failure(_('You cannot use html tags for this type of record.'));
        }
        return ValidationResult::success($string);
    }

    /**
     * Verify that the content is properly quoted
     *
     * @param string $content
     * @return ValidationResult Validation result with error message if quotes not escaped
     */
    public static function validateProperQuoting(string $content): ValidationResult
    {
        $startsWithQuote = isset($content[0]) && $content[0] === '"';
        $endsWithQuote = isset($content[strlen($content) - 1]) && $content[strlen($content) - 1] === '"';

        if ($startsWithQuote && $endsWithQuote) {
            $subContent = substr($content, 1, -1);
        } else {
            $subContent = $content;
        }

        $pattern = '/(?<!\\\\)"/';

        if (preg_match($pattern, $subContent)) {
            return ValidationResult::failure(_('Backslashes must precede all quotes (") in TXT content'));
        }

        return ValidationResult::success($content);
    }

    /**
     * Verify that the string is enclosed in quotes
     *
     * @param string $string Input string
     * @return ValidationResult Validation result with error message if not enclosed in quotes
     */
    public static function validateQuotesAround(string $string): ValidationResult
    {
        // Ignore empty line
        if (strlen($string) === 0) {
            return ValidationResult::success($string);
        }

        if (!str_starts_with($string, '"') || !str_ends_with($string, '"')) {
            return ValidationResult::failure(_('Add quotes around TXT record content.'));
        }

        return ValidationResult::success($string);
    }

    /**
     * Test if string is printable (legacy method for backward compatibility)
     *
     * @deprecated Use validatePrintable() instead which returns a ValidationResult
     * @param string $string string to validate
     * @return boolean true if valid, false otherwise
     */
    public static function isValidPrintable(string $string): bool
    {
        return preg_match('/^[[:print:]]+$/', trim($string)) === 1;
    }

    /**
     * Test if string has html opening and closing tags (legacy method for backward compatibility)
     *
     * @deprecated Use validateNoHtmlTags() instead which returns a ValidationResult
     * @param string $string Input string
     * @return bool true if HTML tags are found, false otherwise
     */
    public static function hasHtmlTags(string $string): bool
    {
        return preg_match('/[<>]/', trim($string)) === 1;
    }

    /**
     * Verify that the content is properly quoted (legacy method for backward compatibility)
     *
     * @deprecated Use validateProperQuoting() instead which returns a ValidationResult
     * @param string $content
     * @return bool
     */
    public static function isProperlyQuoted(string $content): bool
    {
        $startsWithQuote = isset($content[0]) && $content[0] === '"';
        $endsWithQuote = isset($content[strlen($content) - 1]) && $content[strlen($content) - 1] === '"';

        if ($startsWithQuote && $endsWithQuote) {
            $subContent = substr($content, 1, -1);
        } else {
            $subContent = $content;
        }

        $pattern = '/(?<!\\\\)"/';

        return !preg_match($pattern, $subContent);
    }

    /**
     * Verify that the string is enclosed in quotes (legacy method for backward compatibility)
     *
     * @deprecated Use validateQuotesAround() instead which returns a ValidationResult
     * @param string $string Input string
     * @return bool true if valid, false otherwise
     */
    public static function hasQuotesAround(string $string): bool
    {
        // Ignore empty line
        if (strlen($string) === 0) {
            return true;
        }

        return str_starts_with($string, '"') && str_ends_with($string, '"');
    }

    /**
     * Test if string is a valid domain name
     *
     * @param string $domain Domain name to validate
     * @return bool true if valid, false otherwise
     */
    public static function isValidDomain(string $domain): bool
    {
        // Check for invalid characters (only a-z, A-Z, 0-9, hyphen and dots allowed)
        if (!preg_match('/^[a-zA-Z0-9.-]+$/', $domain)) {
            return false;
        }

        // Check for valid domain format
        // Domain parts limited to 63 chars, total length <= 253
        if (strlen($domain) > 253) {
            return false;
        }

        // Check each domain label (part between dots)
        $labels = explode('.', $domain);
        foreach ($labels as $label) {
            // Each label must be 1-63 characters long
            if (strlen($label) < 1 || strlen($label) > 63) {
                return false;
            }

            // Labels cannot begin or end with hyphens
            if (str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a domain name and return validation result
     *
     * @param string $domain Domain name to validate
     * @return ValidationResult ValidationResult with error message if invalid
     */
    public static function validateDomain(string $domain): ValidationResult
    {
        if (!self::isValidDomain($domain)) {
            return ValidationResult::failure(_('Invalid domain name format.'));
        }
        return ValidationResult::success($domain);
    }
}
