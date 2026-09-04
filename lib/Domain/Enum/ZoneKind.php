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


namespace Poweradmin\Domain\Enum;

/**
 * Zone kinds PowerDNS accepts, as stored in `domains.type`.
 *
 * {@see \Poweradmin\Domain\Model\ZoneType} delegates here and remains for
 * callers that work in strings.
 */
enum ZoneKind: string
{
    case MASTER = 'MASTER';
    case SLAVE = 'SLAVE';
    case NATIVE = 'NATIVE';
    case PRODUCER = 'PRODUCER';
    case CONSUMER = 'CONSUMER';

    /**
     * Resolve a stored value, accepting any casing. Null for anything unknown,
     * so callers decide whether that is a rejection or a fallback.
     */
    public static function tryFromName(?string $type): ?self
    {
        return $type === null ? null : self::tryFrom(strtoupper($type));
    }

    /**
     * The three kinds that exist on every PowerDNS install.
     *
     * @return array<string>
     */
    public static function basicValues(): array
    {
        return [self::MASTER->value, self::SLAVE->value, self::NATIVE->value];
    }

    /**
     * Every kind, including the catalog kinds.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn(self $case) => $case->value, self::cases());
    }

    /**
     * Records arrive by transfer from a configured primary, so they must never
     * be seeded locally and the zone needs masters set.
     */
    public function replicatesFromPrimary(): bool
    {
        return $this === self::SLAVE || $this === self::CONSUMER;
    }

    /**
     * Whether Poweradmin blocks record edits regardless of permissions. Asks a
     * different question from replicatesFromPrimary(), of the same set for now.
     */
    public function isReadOnly(): bool
    {
        return $this->replicatesFromPrimary();
    }

    /**
     * Whether PowerDNS sends NOTIFY for this kind.
     */
    public function notifies(): bool
    {
        return $this === self::MASTER || $this === self::PRODUCER;
    }

    /**
     * Kinds the caller may create, given server support and whether they may
     * add a zone replicating from a remote primary.
     *
     * @return array<string>
     */
    public static function creatableValues(bool $catalogSupported, bool $mayAddSecondary): array
    {
        $types = [self::MASTER->value, self::NATIVE->value];

        if (!$catalogSupported) {
            return $types;
        }

        $types[] = self::PRODUCER->value;

        if ($mayAddSecondary) {
            $types[] = self::CONSUMER->value;
        }

        return $types;
    }
}
