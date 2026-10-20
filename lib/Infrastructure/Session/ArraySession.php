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

namespace Poweradmin\Infrastructure\Session;

use Poweradmin\Domain\Port\SessionInterface;

/**
 * A session held in memory: what a test injects, and what a request without a
 * session (the public API) uses as a request-scoped cache.
 */
final class ArraySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $values;

    private string $id;

    private bool $active = true;

    /** @param array<string, mixed> $values The values the session starts with */
    public function __construct(array $values = [])
    {
        $this->values = $values;
        $this->id = bin2hex(random_bytes(16));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function all(): array
    {
        return $this->values;
    }

    public function clear(): void
    {
        $this->values = [];
    }

    public function start(): void
    {
        $this->active = true;
    }

    public function writeClose(): void
    {
        $this->active = false;
    }

    public function regenerateId(bool $deleteOldSession = true): void
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function destroy(): void
    {
        $this->values = [];
        $this->active = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function id(): string
    {
        return $this->id;
    }
}
