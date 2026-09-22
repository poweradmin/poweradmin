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
 *
 */

namespace Poweradmin\Domain\Model;

use ArrayAccess;
use JsonSerializable;
use LogicException;

/**
 * One record of a zone listing, as the export, template-save and console paths
 * read it.
 *
 * The two backends disagreed about the shape: the SQL repository returned
 * disabled as a bool and comments under comment/comment_account, while the API
 * repository returned disabled as 0/1 and comments under api_comment and
 * friends unless a caller asked for them. fromRow() settles both, so a consumer
 * no longer has to know which backend produced the row. Reading it as an array
 * keeps the column-keyed shape the templates and exporters already use.
 *
 * @implements ArrayAccess<string, mixed>
 */
final readonly class RecordRow implements ArrayAccess, JsonSerializable
{
    /**
     * @param int|string $id Numeric on the SQL backend, an encoded string on the API backend
     * @param int|null $modifiedAt PowerDNS 4.9+ through the API backend only
     * @param int|null $changeDate SQL backend only
     * @param string|null $ordername SQL backend only
     * @param bool|null $auth SQL backend only
     */
    public function __construct(
        public int|string $id,
        public int $domainId,
        public string $name,
        public string $type,
        public string $content,
        public int $ttl,
        public int $prio,
        public bool $disabled,
        public ?string $comment = null,
        public ?string $commentAccount = null,
        public ?int $commentModifiedAt = null,
        public ?int $modifiedAt = null,
        public ?int $changeDate = null,
        public ?string $ordername = null,
        public ?bool $auth = null,
    ) {
    }

    /**
     * Build from either backend's row. Comments are read from the SQL names
     * first and the API names second, so a row that carries neither yields null.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $comment = $row['comment'] ?? $row['api_comment'] ?? null;
        $commentAccount = $row['comment_account'] ?? $row['api_comment_account'] ?? null;
        $commentModifiedAt = $row['comment_modified_at'] ?? $row['api_comment_modified_at'] ?? null;

        $id = $row['id'] ?? 0;

        return new self(
            is_numeric($id) ? (int)$id : (string)$id,
            (int)($row['domain_id'] ?? 0),
            (string)($row['name'] ?? ''),
            (string)($row['type'] ?? ''),
            (string)($row['content'] ?? ''),
            (int)($row['ttl'] ?? 0),
            (int)($row['prio'] ?? 0),
            (bool)($row['disabled'] ?? false),
            $comment === null ? null : (string)$comment,
            $commentAccount === null ? null : (string)$commentAccount,
            $commentModifiedAt === null ? null : (int)$commentModifiedAt,
            isset($row['modified_at']) ? (int)$row['modified_at'] : null,
            isset($row['change_date']) ? (int)$row['change_date'] : null,
            isset($row['ordername']) ? (string)$row['ordername'] : null,
            isset($row['auth']) ? (bool)$row['auth'] : null,
        );
    }

    /**
     * The column-keyed shape. Backend-specific columns appear only when the row
     * carried them, the way the repositories behaved before.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = [
            'id' => $this->id,
            'domain_id' => $this->domainId,
            'name' => $this->name,
            'type' => $this->type,
            'content' => $this->content,
            'ttl' => $this->ttl,
            'prio' => $this->prio,
            'disabled' => $this->disabled,
            'comment' => $this->comment,
            'comment_account' => $this->commentAccount,
            'comment_modified_at' => $this->commentModifiedAt,
            'modified_at' => $this->modifiedAt,
        ];

        foreach (['change_date' => $this->changeDate, 'ordername' => $this->ordername, 'auth' => $this->auth] as $key => $value) {
            if ($value !== null) {
                $row[$key] = $value;
            }
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
        return array_key_exists((string)$offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[(string)$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('A record row is read-only; build a new one instead of writing ' . (string)$offset);
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('A record row is read-only; ' . (string)$offset . ' cannot be removed');
    }
}
