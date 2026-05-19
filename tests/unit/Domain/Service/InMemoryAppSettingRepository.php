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

namespace Poweradmin\Tests\Unit\Domain\Service;

use Poweradmin\Domain\Repository\AppSettingRepositoryInterface;

/**
 * Test stub used by AppSettingsServiceTest. Tracks find()/save() calls so
 * tests can assert memoization and write semantics.
 */
final class InMemoryAppSettingRepository implements AppSettingRepositoryInterface
{
    public int $findCalls = 0;
    /** @var list<string> */
    public array $savedKeys = [];

    /**
     * @param array<string, array{value: string, type: string}> $rows
     */
    public function __construct(private array $rows = [])
    {
    }

    public function find(string $key): ?array
    {
        $this->findCalls++;
        return $this->rows[$key] ?? null;
    }

    public function findAll(): array
    {
        return $this->rows;
    }

    public function findByPrefix(string $prefix): array
    {
        $out = [];
        foreach ($this->rows as $key => $row) {
            if (str_starts_with($key, $prefix)) {
                $out[$key] = $row;
            }
        }
        return $out;
    }

    public function save(string $key, string $value, string $type = 'string'): void
    {
        $this->rows[$key] = ['value' => $value, 'type' => $type];
        $this->savedKeys[] = $key;
    }

    public function delete(string $key): void
    {
        unset($this->rows[$key]);
    }

    public function isReady(): bool
    {
        return true;
    }
}
