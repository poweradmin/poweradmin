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

/**
 * A filed change request: the actions a requester wants applied to a zone,
 * who filed it, and how a reviewer decided it.
 */
final readonly class ZoneChangeRequest
{
    public const KIND_RECORDS = 'records';
    public const KIND_ZONE_DELETE = 'zone_delete';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    public const OP_ADD = 'add';
    public const OP_EDIT = 'edit';
    public const OP_DELETE = 'delete';
    public const OP_ZONE_DELETE = 'zone_delete';

    /**
     * @param list<array<string, mixed>> $actions The decoded payload actions, each with an "op" key
     * @param string|null $zoneComment The zone comment to set, or null when it is left alone
     */
    public function __construct(
        public int $id,
        public int $zoneId,
        public string $zoneName,
        public string $kind,
        public string $status,
        public ?int $requesterId,
        public string $requesterName,
        public ?string $requestComment,
        public ?string $baseSerial,
        public array $actions,
        public ?string $zoneComment,
        public ?int $reviewerId,
        public ?string $reviewerName,
        public ?string $reviewComment,
        public string $createdAt,
        public ?string $reviewedAt,
        public ?string $appliedAt,
        public ?string $error
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Pending requests are applied on approval; a failed one may be tried again.
     */
    public function canBeApplied(): bool
    {
        return $this->status === self::STATUS_PENDING || $this->status === self::STATUS_FAILED;
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_FAILED], true);
    }

    public function isZoneDelete(): bool
    {
        return $this->kind === self::KIND_ZONE_DELETE;
    }

    public function actionCount(): int
    {
        return count($this->actions);
    }

    /**
     * The stored payload shape: {"actions": [...], "zone_comment": null|string}.
     *
     * @param list<array<string, mixed>> $actions
     */
    public static function encodePayload(array $actions, ?string $zoneComment): string
    {
        $json = json_encode(['actions' => $actions, 'zone_comment' => $zoneComment], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{"actions":[],"zone_comment":null}' : $json;
    }

    /**
     * @return array{actions: list<array<string, mixed>>, zone_comment: string|null}
     */
    public static function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        $actions = is_array($decoded) && isset($decoded['actions']) && is_array($decoded['actions'])
            ? array_values(array_filter($decoded['actions'], 'is_array'))
            : [];
        $zoneComment = is_array($decoded) && isset($decoded['zone_comment']) && is_string($decoded['zone_comment'])
            ? $decoded['zone_comment']
            : null;

        return ['actions' => $actions, 'zone_comment' => $zoneComment];
    }
}
