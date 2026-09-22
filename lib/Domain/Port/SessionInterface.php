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

namespace Poweradmin\Domain\Port;

/**
 * The request's session store. One implementation wraps PHP's own session, the
 * other keeps the values in memory for tests and for request-scoped caches.
 */
interface SessionInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;

    /** @return array<string, mixed> Every stored value, keyed by session key */
    public function all(): array;

    /** Drops every stored value, keeping the session itself open */
    public function clear(): void;

    /** Opens the session when it is not open yet */
    public function start(): void;

    /** Writes the session out and closes it for this request */
    public function writeClose(): void;

    public function regenerateId(bool $deleteOldSession = true): void;

    public function destroy(): void;

    public function isActive(): bool;

    /** The session id, an empty string when no session is open */
    public function id(): string;
}
