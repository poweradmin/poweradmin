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

use Poweradmin\Infrastructure\Service\MessageService;

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
     *
     * @return boolean true if valid, false otherwise
     */
    public static function isValidPrintable(string $string): bool
    {
        if (!preg_match('/^[[:print:]]+$/', trim($string))) {
            (new MessageService())->addSystemError(_('Invalid characters have been used in this record.'));
            return false;
        }
        return true;
    }

    /**
     * Test if string has html opening and closing tags
     *
     * @param string $string Input string
     * @return bool true if HTML tags are found, false otherwise
     */
    public static function hasHtmlTags(string $string): bool
    {
        // Method should return true if the string contains HTML tags, false otherwise
        $contains_tags = preg_match('/[<>]/', trim($string));
        if ($contains_tags) {
            (new MessageService())->addSystemError(_('You cannot use html tags for this type of record.'));
        }
        return $contains_tags;
    }

    /**
     * Verify that the content is properly quoted
     *
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

        if (preg_match($pattern, $subContent)) {
            (new MessageService())->addSystemError(_('Backslashes must precede all quotes (") in TXT content'));
            return false;
        }

        return true;
    }

    /**
     * Verify that the string is enclosed in quotes
     *
     * @param string $string Input string
     * @return bool true if valid, false otherwise
     */
    public static function hasQuotesAround(string $string): bool
    {
        // Ignore empty line
        if (strlen($string) === 0) {
            return true;
        }

        if (!str_starts_with($string, '"') || !str_ends_with($string, '"')) {
            (new MessageService())->addSystemError(_('Add quotes around TXT record content.'));
            return false;
        }

        return true;
    }
}
