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
use JsonSerializable;
use LogicException;
use Poweradmin\Domain\Enum\ZoneSoaHealth;
use Poweradmin\Domain\Utility\DnsIdnService;

/**
 * One row of a zone list: the forward and reverse pages, the internal API list
 * and the console listing.
 *
 * Both the SQL and the API backend build it through fromRow(), so the key set the
 * templates and the internal API see is defined once. Reading it as an array
 * (zone['name'] in Twig, toArray() for JSON) yields the legacy column-keyed shape;
 * the optional columns (serial, template, ...) appear only when the listing asked
 * for them, as before.
 *
 * @implements ArrayAccess<string, mixed>
 */
final readonly class ZoneSummary implements ArrayAccess, JsonSerializable
{
    /**
     * @param list<string> $owners Usernames of every owner, primary owner first
     * @param list<string> $fullNames Full name per owner, empty when the user has none
     * @param ZoneSoaHealth|null $soaHealth Null when the listing does not carry health
     * @param string|null $serial SOA serial, '' when unknown; null when not requested
     * @param string|null $signedSerial DNSSEC-edited serial; null when not requested
     * @param string|null $template Applied template name, '' when none; null when not requested
     * @param int|null $notifiedSerial Serial last acknowledged by secondaries, API backend only
     * @param bool|null $notifyPending Whether serial is ahead of notifiedSerial
     * @param int|null $canonicalId The id the other endpoints key on, API backend internal list only
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $utf8Name,
        public string $type,
        public int $recordCount,
        public string $comment,
        public bool $secured,
        public array $owners,
        public array $fullNames,
        public ?ZoneSoaHealth $soaHealth = null,
        public ?string $serial = null,
        public ?string $signedSerial = null,
        public ?string $template = null,
        public ?int $notifiedSerial = null,
        public ?bool $notifyPending = null,
        public ?int $canonicalId = null,
    ) {
    }

    /**
     * Build from the column-keyed row a repository assembled: id, name, type,
     * count_records, comment, secured, owners, full_names, and the optional
     * soa_health, serial, signed_serial, template, notified_serial, notify_pending
     * and canonical_id.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $name = (string)$row['name'];

        return new self(
            (int)$row['id'],
            $name,
            (string)($row['utf8_name'] ?? DnsIdnService::toUtf8($name)),
            (string)($row['type'] ?? 'NATIVE'),
            (int)($row['count_records'] ?? 0),
            (string)($row['comment'] ?? ''),
            (bool)($row['secured'] ?? false),
            array_values(array_map(fn($owner) => (string)$owner, $row['owners'] ?? [])),
            array_values(array_map(fn($fullName) => (string)$fullName, $row['full_names'] ?? [])),
            isset($row['soa_health']) ? ZoneSoaHealth::from((string)$row['soa_health']) : null,
            array_key_exists('serial', $row) ? (string)$row['serial'] : null,
            array_key_exists('signed_serial', $row) ? (string)$row['signed_serial'] : null,
            array_key_exists('template', $row) ? (string)$row['template'] : null,
            isset($row['notified_serial']) ? (int)$row['notified_serial'] : null,
            isset($row['notify_pending']) ? (bool)$row['notify_pending'] : null,
            isset($row['canonical_id']) ? (int)$row['canonical_id'] : null,
        );
    }

    public function primaryOwner(): ?string
    {
        return $this->owners[0] ?? null;
    }

    /**
     * The column-keyed array the zone lists returned before this model existed.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = ['id' => $this->id];
        if ($this->canonicalId !== null) {
            $row['canonical_id'] = $this->canonicalId;
        }
        $row += [
            'name' => $this->name,
            'utf8_name' => $this->utf8Name,
            'type' => $this->type,
            'count_records' => $this->recordCount,
        ];
        if ($this->soaHealth !== null) {
            $row += $this->soaHealth->toZoneFields();
        }
        $row += [
            'comment' => $this->comment,
            'secured' => $this->secured,
            'owners' => $this->owners,
            'full_names' => $this->fullNames,
            'users' => $this->owners,
        ];
        if ($this->serial !== null) {
            $row['serial'] = $this->serial;
        }
        if ($this->signedSerial !== null) {
            $row['signed_serial'] = $this->signedSerial;
        }
        if ($this->template !== null) {
            $row['template'] = $this->template;
        }
        if ($this->notifiedSerial !== null) {
            $row['notified_serial'] = $this->notifiedSerial;
            $row['notify_pending'] = (bool)$this->notifyPending;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
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
        throw new LogicException('ZoneSummary is read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('ZoneSummary is read-only');
    }
}
