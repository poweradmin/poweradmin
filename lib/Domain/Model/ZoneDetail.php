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

namespace Poweradmin\Domain\Model;

use ArrayAccess;
use LogicException;
use Poweradmin\Domain\Utility\DnsIdnService;

/**
 * One zone with its ownership, as read by ZoneReadRepositoryInterface::getZone().
 *
 * Both the SQL and the API backend build it through fromRow(), so the key set the
 * internal API and the templates see is defined once. Reading it as an array
 * (zone['name'] in Twig, toArray() for JSON) yields the legacy column-keyed shape.
 *
 * @implements ArrayAccess<string, mixed>
 */
final readonly class ZoneDetail implements ArrayAccess
{
    /**
     * @param list<string> $owners Usernames of every owner, primary owner first
     * @param list<string|null> $fullNames Full name per owner, null when the user has none
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $utf8Name,
        public string $type,
        public ?string $master,
        public ?string $account,
        public int $owner,
        public string $comment,
        public int $recordCount,
        public bool $secured,
        public array $owners,
        public array $fullNames,
    ) {
    }

    /**
     * Build from the getZoneById() core row plus comment and secured, with the owner
     * rows the repository already looked up.
     *
     * @param array<string, mixed> $row id, name, type, master, account, owner, comment, record_count, secured
     * @param list<array{username: string, fullname: string|null}> $owners
     */
    public static function fromRow(array $row, array $owners): self
    {
        return new self(
            (int)$row['id'],
            (string)$row['name'],
            DnsIdnService::toUtf8((string)$row['name']),
            (string)$row['type'],
            self::nullableString($row['master'] ?? null),
            self::nullableString($row['account'] ?? null),
            (int)($row['owner'] ?? 0),
            (string)($row['comment'] ?? ''),
            (int)$row['record_count'],
            (bool)($row['secured'] ?? false),
            array_values(array_map(fn(array $owner) => (string)$owner['username'], $owners)),
            array_values(array_map(fn(array $owner) => self::nullableString($owner['fullname']), $owners)),
        );
    }

    public function primaryOwner(): ?string
    {
        return $this->owners[0] ?? null;
    }

    /**
     * The column-keyed array getZone() returned before this model existed.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'master' => $this->master,
            'account' => $this->account,
            'owner' => $this->owner,
            'comment' => $this->comment,
            'record_count' => $this->recordCount,
            'secured' => $this->secured,
            'count_records' => $this->recordCount,
            'username' => $this->primaryOwner(),
            'fullname' => $this->fullNames[0] ?? null,
            'utf8_name' => $this->utf8Name,
            'owners' => $this->owners,
            'full_names' => array_map(fn(?string $name) => $name ?: '', $this->fullNames),
            'users' => $this->owners,
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('ZoneDetail is read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('ZoneDetail is read-only');
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}
