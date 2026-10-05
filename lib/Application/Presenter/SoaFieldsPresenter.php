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

use Poweradmin\Domain\Utility\SoaContent;

/**
 * Attaches the labelled SOA breakdown to record rows so the templates can show it.
 */
class SoaFieldsPresenter
{
    /**
     * @param array<int, array<string, mixed>> $records Rows with their stored content
     * @return array<int, array<string, mixed>> The same rows, each with soa_fields (null unless a seven-field SOA)
     */
    public static function decorate(array $records): array
    {
        foreach ($records as &$record) {
            $record['soa_fields'] = self::forRecord($record);
        }
        unset($record);

        return $records;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, string>|null
     */
    public static function forRecord(array $record): ?array
    {
        if (($record['type'] ?? '') !== 'SOA') {
            return null;
        }

        return SoaContent::parse((string)($record['content'] ?? ''));
    }
}
