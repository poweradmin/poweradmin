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

namespace Poweradmin\Application\Http;

/**
 * What a POST to the zone editor is asking for, decided from which form fields
 * arrived. The zone edit page carries several forms in one page: the inline
 * add-record form (name/content/type), the record table (record rows plus the
 * always-sent form_complete marker), and the zone comment.
 *
 * SAVE_TRUNCATED is the max_input_vars case: PHP dropped the trailing fields
 * including the submit button, so record rows arrive without `commit`. The save
 * still runs so complete rows are kept and the operator is warned.
 */
enum ZoneEditIntent
{
    case NONE;
    case ADD_RECORD;
    case SAVE_RECORDS;
    case SAVE_TRUNCATED;

    /**
     * @param array<string, mixed> $post The POST fields as submitted
     */
    public static function from(bool $isPost, array $post): self
    {
        if (!$isPost) {
            return self::NONE;
        }

        $has = static fn(string $key): bool => ($post[$key] ?? null) !== null;

        if ($has('commit')) {
            if (self::hasAddRecordFields($post)) {
                // Record rows alongside the add fields mean a full-page save;
                // the add fields alone mean the inline add form was submitted
                return $has('record') ? self::SAVE_RECORDS : self::ADD_RECORD;
            }

            // A save may carry only rows, only a zone comment, or - when the
            // client omitted every unchanged row - just the form_complete
            // marker, which still bumps the SOA serial as before
            if ($has('record') || $has('zone_comment') || $has('form_complete')) {
                return self::SAVE_RECORDS;
            }

            return self::NONE;
        }

        return $has('record') ? self::SAVE_TRUNCATED : self::NONE;
    }

    /**
     * Whether the inline add-record form arrived with the POST. Callers stash
     * these fields for re-display before processing, in case validation fails.
     *
     * @param array<string, mixed> $post The POST fields as submitted
     */
    public static function hasAddRecordFields(array $post): bool
    {
        return ($post['name'] ?? null) !== null
            && ($post['content'] ?? null) !== null
            && ($post['type'] ?? null) !== null;
    }
}
