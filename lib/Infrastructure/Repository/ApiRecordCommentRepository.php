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

namespace Poweradmin\Infrastructure\Repository;

use Poweradmin\Domain\Model\RecordComment;
use Poweradmin\Domain\Repository\RecordCommentRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

/**
 * API-backend record comment repository.
 *
 * Uses PowerDNS REST API RRset comments as the sole storage backend.
 * Comments are per-RRset (name + type), not per individual record.
 * No database fallback - the API is the single source of truth.
 */
class ApiRecordCommentRepository implements RecordCommentRepositoryInterface
{
    private PowerdnsApiClient $apiClient;
    private DnsBackendProvider $backendProvider;

    public function __construct(PowerdnsApiClient $apiClient, DnsBackendProvider $backendProvider)
    {
        $this->apiClient = $apiClient;
        $this->backendProvider = $backendProvider;
    }

    public function add(RecordComment $comment): RecordComment
    {
        return $this->writeRRsetComment($comment);
    }

    public function delete(int $domainId, string $name, string $type): bool
    {
        $zoneName = $this->backendProvider->getZoneNameById($domainId);
        if ($zoneName === null) {
            return false;
        }

        return $this->patchRRsetComments($zoneName, $name, $type, []);
    }

    public function deleteLegacyComment(int $domainId, string $name, string $type): bool
    {
        return $this->delete($domainId, $name, $type);
    }

    public function deleteByDomainId(int $domainId): void
    {
        $zoneName = $this->backendProvider->getZoneNameById($domainId);
        if ($zoneName === null) {
            return;
        }

        $apiZoneName = $this->ensureTrailingDot($zoneName);
        $zoneData = $this->apiClient->getZone($apiZoneName);
        if ($zoneData === null) {
            return;
        }

        $rrsets = [];
        foreach ($zoneData['rrsets'] ?? [] as $rrset) {
            if (!empty($rrset['comments'])) {
                $rrsets[] = [
                    'name' => $rrset['name'],
                    'type' => $rrset['type'],
                    'changetype' => 'REPLACE',
                    'records' => $rrset['records'] ?? [],
                    'ttl' => $rrset['ttl'] ?? 3600,
                    'comments' => [],
                ];
            }
        }

        if (!empty($rrsets)) {
            $this->apiClient->patchZoneRRsets($apiZoneName, $rrsets);
        }
    }

    public function find(int $domainId, string $name, string $type): ?RecordComment
    {
        $zoneName = $this->backendProvider->getZoneNameById($domainId);
        if ($zoneName === null) {
            return null;
        }

        $comment = $this->getRRsetComment($zoneName, $name, $type);
        if ($comment === null) {
            return null;
        }

        return new RecordComment(
            0,
            $domainId,
            $name,
            $type,
            $comment['modified_at'] ?? 0,
            $comment['account'] ?? null,
            $comment['content'] ?? ''
        );
    }

    public function update(int $domainId, string $oldName, string $oldType, RecordComment $comment): ?RecordComment
    {
        $zoneName = $this->backendProvider->getZoneNameById($domainId);
        if ($zoneName === null) {
            return null;
        }

        $result = $this->writeRRsetComment($comment);

        // Clear old RRset comment if name/type changed
        if ($oldName !== $comment->getName() || $oldType !== $comment->getType()) {
            $this->patchRRsetComments($zoneName, $oldName, $oldType, []);
        }

        return $result;
    }

    public function findByRecordId(int|string $recordId): ?RecordComment
    {
        // Per-record comments are not supported in API mode.
        // Comments are shared across all records in an RRset.
        return null;
    }

    public function deleteByRecordId(int|string $recordId): bool
    {
        // Per-record deletes are a no-op in API mode to avoid
        // clearing the shared RRset comment.
        return true;
    }

    public function linkRecordToComment(int|string $recordId, int $commentId): bool
    {
        // Per-record linking is not supported in API mode.
        return true;
    }

    public function unlinkRecord(int|string $recordId): bool
    {
        // Per-record unlinking is not supported in API mode.
        return true;
    }

    public function addForRecord(int|string $recordId, RecordComment $comment): ?RecordComment
    {
        // Per-record isolation is not possible in API mode since comments
        // are per-RRset. Write the comment as an RRset-level API comment.
        if ($comment->getComment() === '') {
            $zoneName = $this->backendProvider->getZoneNameById($comment->getDomainId());
            if ($zoneName !== null) {
                $this->patchRRsetComments($zoneName, $comment->getName(), $comment->getType(), []);
            }
            return null;
        }

        return $this->writeRRsetComment($comment);
    }

    public function migrateLegacyComments(int $domainId, string $name, string $type, int|string $excludeRecordId): bool
    {
        // Not applicable for API mode
        return false;
    }

    /**
     * Write an RRset-level comment to the PowerDNS API.
     */
    private function writeRRsetComment(RecordComment $comment): RecordComment
    {
        $zoneName = $this->backendProvider->getZoneNameById($comment->getDomainId());
        if ($zoneName !== null) {
            $this->patchRRsetComments($zoneName, $comment->getName(), $comment->getType(), [
                [
                    'content' => $comment->getComment(),
                    'account' => $comment->getAccount() ?? '',
                    'modified_at' => $comment->getModifiedAt(),
                ]
            ]);
        }

        return new RecordComment(
            0,
            $comment->getDomainId(),
            $comment->getName(),
            $comment->getType(),
            $comment->getModifiedAt(),
            $comment->getAccount(),
            $comment->getComment()
        );
    }

    /**
     * Get the first comment from an RRset via the PowerDNS API.
     */
    private function getRRsetComment(string $zoneName, string $name, string $type): ?array
    {
        $apiZoneName = $this->ensureTrailingDot($zoneName);
        $zoneData = $this->apiClient->getZone($apiZoneName);
        if ($zoneData === null) {
            return null;
        }

        $apiRecordName = $this->ensureTrailingDot($name);
        foreach ($zoneData['rrsets'] ?? [] as $rrset) {
            if ($rrset['name'] === $apiRecordName && $rrset['type'] === $type) {
                $comments = $rrset['comments'] ?? [];
                return !empty($comments) ? $comments[0] : null;
            }
        }

        return null;
    }

    /**
     * PATCH an RRset to update only its comments, preserving existing records.
     */
    private function patchRRsetComments(string $zoneName, string $name, string $type, array $comments): bool
    {
        $apiZoneName = $this->ensureTrailingDot($zoneName);
        $apiRecordName = $this->ensureTrailingDot($name);

        // Fetch current RRset to preserve records
        $zoneData = $this->apiClient->getZone($apiZoneName);
        if ($zoneData === null) {
            return false;
        }

        $currentRecords = [];
        $currentTtl = 3600;
        foreach ($zoneData['rrsets'] ?? [] as $rrset) {
            if ($rrset['name'] === $apiRecordName && $rrset['type'] === $type) {
                $currentRecords = $rrset['records'] ?? [];
                $currentTtl = $rrset['ttl'] ?? 3600;
                break;
            }
        }

        // If no records exist for this RRset, we can't attach comments
        if (empty($currentRecords)) {
            return false;
        }

        return $this->apiClient->patchZoneRRsets($apiZoneName, [
            [
                'name' => $apiRecordName,
                'type' => $type,
                'ttl' => $currentTtl,
                'changetype' => 'REPLACE',
                'records' => $currentRecords,
                'comments' => $comments,
            ]
        ]);
    }

    private function ensureTrailingDot(string $name): string
    {
        return str_ends_with($name, '.') ? $name : $name . '.';
    }
}
