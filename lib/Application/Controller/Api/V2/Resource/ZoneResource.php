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

namespace Poweradmin\Application\Controller\Api\V2\Resource;

/**
 * The JSON shape of a zone on /api/v2: the listing carries a summary, the
 * single-zone endpoints the full detail.
 */
final class ZoneResource
{
    /**
     * A zone as GET /zones lists it.
     *
     * @param array<string, mixed> $zone
     * @return array<string, mixed>
     */
    public static function summary(array $zone): array
    {
        return [
            'id' => (int)$zone['id'],
            // Equal to id except for API-backend zones migrated from SQL mode; the
            // value every other endpoint accepts. id will follow it in a later release.
            'canonical_id' => (int)($zone['canonical_id'] ?? $zone['id']),
            'name' => $zone['name'],
            'type' => $zone['type'] ?? 'MASTER',
            'created_at' => $zone['created_at'] ?? null,
        ];
    }

    /**
     * A zone as GET and PUT /zones/{id} emit it; empty masters, account and
     * description read as null.
     *
     * @param array<string, mixed> $zone
     * @return array<string, mixed>
     */
    public static function detail(array $zone, ?string $comment): array
    {
        $account = $zone['account'] ?? '';
        $masters = $zone['master'] ?? '';

        return [
            'id' => (int)$zone['id'],
            'name' => $zone['name'],
            'type' => $zone['type'] ?? 'MASTER',
            'masters' => $masters !== '' ? $masters : null,
            'account' => $account !== '' ? $account : null,
            'description' => ($comment !== null && $comment !== '') ? $comment : null,
            'created_at' => $zone['created_at'] ?? null,
        ];
    }
}
