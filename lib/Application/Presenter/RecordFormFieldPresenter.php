<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Application\Presenter;

use Poweradmin\Domain\Service\Validation\RecordField;

/**
 * Maps a refused record write to the record form input it should highlight.
 *
 * Validators that name the offending part win; for the rest the message text
 * is read the way the forms always did, until every validator names its field.
 */
final class RecordFormFieldPresenter
{
    public const FIELD_NAME = 'name';
    public const FIELD_CONTENT = 'content';
    public const FIELD_TTL = 'ttl';
    public const FIELD_PRIO = 'prio';
    public const FIELD_DUPLICATE = 'name-content-duplicate';

    public static function fieldId(?RecordField $field, string $message): string
    {
        return $field === null ? self::fieldForMessage($message) : self::idFor($field);
    }

    public static function idFor(RecordField $field): string
    {
        return match ($field) {
            RecordField::NAME => self::FIELD_NAME,
            RecordField::CONTENT => self::FIELD_CONTENT,
            RecordField::TTL => self::FIELD_TTL,
            RecordField::PRIO => self::FIELD_PRIO,
            RecordField::DUPLICATE => self::FIELD_DUPLICATE,
        };
    }

    public static function fieldForMessage(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'already exists')) {
            return self::FIELD_DUPLICATE;
        }
        if (preg_match('/\bname\b/', $lower) && str_contains($lower, 'invalid')) {
            return self::FIELD_NAME;
        }
        foreach (['content', 'value', 'address', 'hostname'] as $hint) {
            if (str_contains($lower, $hint)) {
                return self::FIELD_CONTENT;
            }
        }
        if (str_contains($lower, 'ttl')) {
            return self::FIELD_TTL;
        }
        if (str_contains($lower, 'prio')) {
            return self::FIELD_PRIO;
        }

        return self::FIELD_CONTENT;
    }
}
