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

namespace Poweradmin\Domain\Repository;

/**
 * Persistence boundary for generic admin-managed settings layered above
 * config/settings.php. Keys use dotted notation (e.g. "interface.theme")
 * so they map cleanly onto ConfigurationManager groups during fallback.
 *
 * Values are stored as strings with a separate type hint; the service layer
 * (AppSettingsService) casts on read.
 */
interface AppSettingRepositoryInterface
{
    /**
     * Return the stored value + type for the given key, or null when no
     * row exists.
     *
     * @return array{value: string, type: string}|null
     */
    public function find(string $key): ?array;

    /**
     * Return every stored setting keyed by setting_key.
     *
     * @return array<string, array{value: string, type: string}>
     */
    public function findAll(): array;

    /**
     * Return every stored setting whose key starts with the given prefix
     * (e.g. "interface.") keyed by setting_key.
     *
     * @return array<string, array{value: string, type: string}>
     */
    public function findByPrefix(string $prefix): array;

    /**
     * Insert or update the setting. The repository does not validate the
     * type/value relationship - that is the service layer's job.
     */
    public function save(string $key, string $value, string $type = 'string'): void;

    /**
     * Remove the setting, falling back to ConfigurationManager / caller default.
     */
    public function delete(string $key): void;

    /**
     * Whether the backing table is available. False on installations where
     * the migration hasn't been applied yet, letting consumers degrade to
     * the legacy ConfigurationManager-only path.
     */
    public function isReady(): bool;
}
