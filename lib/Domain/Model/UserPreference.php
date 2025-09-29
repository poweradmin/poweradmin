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

namespace Poweradmin\Domain\Model;

class UserPreference
{
    private int $id;
    private int $userId;
    private string $preferenceKey;
    private ?string $preferenceValue;

    public const KEY_ROWS_PER_PAGE = 'rows_per_page';
    public const KEY_DEFAULT_TTL = 'default_ttl';
    public const KEY_SHOW_ZONE_SERIAL = 'show_zone_serial';
    public const KEY_SHOW_ZONE_TEMPLATE = 'show_zone_template';
    public const KEY_RECORD_FORM_POSITION = 'record_form_position';
    public const KEY_SAVE_BUTTON_POSITION = 'save_button_position';
    public const KEY_DEFAULT_ZONE_VIEW = 'default_zone_view';
    public const KEY_ZONE_SORT_ORDER = 'zone_sort_order';

    /**
     * Valid preference keys
     */
    public const VALID_KEYS = [
        self::KEY_ROWS_PER_PAGE,
        self::KEY_DEFAULT_TTL,
        self::KEY_SHOW_ZONE_SERIAL,
        self::KEY_SHOW_ZONE_TEMPLATE,
        self::KEY_RECORD_FORM_POSITION,
        self::KEY_SAVE_BUTTON_POSITION,
        self::KEY_DEFAULT_ZONE_VIEW,
        self::KEY_ZONE_SORT_ORDER,
    ];

    public function __construct(
        int $id,
        int $userId,
        string $preferenceKey,
        ?string $preferenceValue
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->preferenceKey = $preferenceKey;
        $this->preferenceValue = $preferenceValue;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getPreferenceKey(): string
    {
        return $this->preferenceKey;
    }

    public function getPreferenceValue(): ?string
    {
        return $this->preferenceValue;
    }

    public function setPreferenceValue(?string $value): void
    {
        $this->preferenceValue = $value;
    }

    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::VALID_KEYS, true);
    }
}
