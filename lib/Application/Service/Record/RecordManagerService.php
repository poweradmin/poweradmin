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

namespace Poweradmin\Application\Service\Record;

use Closure;
use Poweradmin\Application\Service\Zone\ChangeApprovalContext;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Utility\DomainUtility;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Application\Service\Web\AuditService;

/**
 * Creates a record through RecordManager, then logs it, stores its comment and syncs the PTR comment.
 */
class RecordManagerService
{
    private DomainRepositoryInterface $domainRepository;
    private RecordRepositoryInterface $recordRepository;
    private RecordManagerInterface $recordManager;
    private RecordCommentService $recordCommentService;
    private AuditService $audit;
    private ConfigurationInterface $config;
    private PermissionService $permissions;
    private ?ChangeApprovalContext $approval;

    public function __construct(
        DomainRepositoryInterface $domainRepository,
        RecordRepositoryInterface $recordRepository,
        RecordManagerInterface $recordManager,
        RecordCommentService $recordCommentService,
        AuditService $audit,
        ConfigurationInterface $config,
        PermissionService $permissions,
        ?ChangeApprovalContext $approval = null
    ) {
        $this->domainRepository = $domainRepository;
        $this->recordRepository = $recordRepository;
        $this->recordManager = $recordManager;
        $this->recordCommentService = $recordCommentService;
        $this->audit = $audit;
        $this->config = $config;
        $this->permissions = $permissions;
        $this->approval = $approval;
    }

    /**
     * Creates the record and, on success, logs, rectifies and stores the comment.
     * Failures come back as the result; nothing is written to MessageService.
     *
     * @param string $origin One of the AuditLoggerInterface::ORIGIN_* values; picks the audit event
     * @param int|null $userId The acting user; the comment reaches a related A/PTR record only in a
     *                         zone this user may edit, and never when null
     * @param bool $finalizeZone Bump the serial and rectify; a batch caller does that once itself
     * @param (Closure(int): bool)|null $syncZoneAllowed Whether the synced comment may reach the related zone (an API key's zone scope)
     */
    public function createRecord(
        int $zone_id,
        string $name,
        string $type,
        string $content,
        int $ttl,
        int $prio,
        string $comment,
        string $userlogin,
        int $disabled = 0,
        string $origin = AuditLoggerInterface::ORIGIN_WEB,
        ?int $userId = null,
        bool $finalizeZone = true,
        ?Closure $syncZoneAllowed = null
    ): RecordWriteResult {
        $zone_name = $this->domainRepository->getDomainNameById($zone_id);

        // All creates go through RecordManager so permission gates and record
        // validation apply to disabled records as well.
        // API backend: carry the comment in the record PATCH; null rather than ''
        // so an empty form field does not clear an existing RRset comment
        $rrsetComment = $comment === '' ? null : ['content' => $comment, 'account' => $userlogin];
        $result = $this->recordManager->addRecordGetId($zone_id, $name, $type, $content, $ttl, $prio, $disabled, $finalizeZone, $rrsetComment);
        if (!$result->success) {
            return $result;
        }

        $fqdn = DnsHelper::restoreZoneSuffix($name, $zone_name);
        if ($origin === AuditLoggerInterface::ORIGIN_API) {
            $this->audit->logApiRecordAdd($zone_id, $fqdn, $type, $content);
        } else {
            $this->audit->logRecordAdd($zone_id, $type, $fqdn, $content, $ttl, $prio);
        }
        $this->handleCommentsWithId($zone_id, $name, $type, $content, $comment, $userlogin, $zone_name, $result->recordId, $userId, $syncZoneAllowed);

        return $result;
    }

    /**
     * Handle comments when record ID is already known (from addRecordGetId).
     * This avoids the need to look up the record ID after creation.
     * Uses the record_comment_links table for per-record comment linking.
     */
    private function handleCommentsWithId(int $zoneId, string $name, string $type, string $content, string $comment, string $userLogin, string $zone_name, int|string $recordId, ?int $userId, ?Closure $zoneAllowed): void
    {
        if ($comment === '') {
            return;
        }

        $fullZoneName = DnsHelper::restoreZoneSuffix($name, $zone_name);

        // Use the provided record ID directly for per-record comment (via linking table)
        $this->recordCommentService->createCommentForRecord(
            $zoneId,
            $fullZoneName,
            $type,
            $comment,
            $recordId,
            $userLogin
        );

        // Handle synced comments (propagate to related A/PTR records)
        if ($userId !== null && $this->config->get('misc', 'record_comments_sync')) {
            $this->handleSyncedComments($zoneId, $name, $type, $content, $comment, $userLogin, $fullZoneName, $userId, $zoneAllowed);
        }
    }

    /**
     * Copy a new record's comment to the companion A or PTR record created right after it.
     * createRecord() syncs before the companion exists, so the add flow calls this once it does.
     *
     * @param (Closure(int): bool)|null $zoneAllowed Whether the comment may reach the companion's zone (an API key's zone scope)
     */
    public function syncCompanionComment(int $zoneId, string $name, string $type, string $content, string $comment, string $userLogin, int $userId, ?Closure $zoneAllowed = null): void
    {
        if ($comment === '' || !$this->config->get('misc', 'record_comments_sync')) {
            return;
        }

        $this->handleSyncedComments($zoneId, $name, $type, $content, $comment, $userLogin, $name, $userId, $zoneAllowed);
    }

    /**
     * Sync comments to related records (A/AAAA <-> PTR).
     * This only syncs to the TARGET record, not the source record
     * (which already has a per-record comment from handleCommentsWithId).
     */
    private function handleSyncedComments(int $zone_id, string $name, string $type, string $content, string $comment, string $userlogin, string $full_name, int $userId, ?Closure $zoneAllowed): void
    {
        if ($type === RecordType::A || $type === RecordType::AAAA) {
            // Sync comment to the corresponding PTR record
            $this->syncCommentToPtrRecord($type, $content, $comment, $userlogin, $userId, $zoneAllowed);
        } elseif ($type === 'PTR') {
            // An ip6.arpa PTR pairs with AAAA records, an in-addr.arpa PTR with A records
            $forwardType = str_ends_with(strtolower($full_name), '.ip6.arpa') ? RecordType::AAAA : RecordType::A;
            $this->syncCommentToARecord($content, $comment, $userlogin, $userId, $forwardType, $zoneAllowed);
        }
        // For other record types, no sync needed - per-record comment is already set
    }

    /**
     * Sync comment from A/AAAA record to corresponding PTR record.
     */
    private function syncCommentToPtrRecord(string $type, string $content, string $comment, string $userlogin, int $userId, ?Closure $zoneAllowed): void
    {
        $ptrName = $type === RecordType::A
            ? DomainUtility::convertIPv4AddrToPtrRec($content)
            : DomainUtility::convertIPv6AddrToPtrRec($content);

        $ptrZoneId = $this->domainRepository->getBestMatchingZoneIdFromName($ptrName);
        if ($ptrZoneId !== -1 && $this->mayEditZone($userId, $ptrZoneId, $zoneAllowed)) {
            $rrsetRecords = $this->recordRepository->getRRSetRecords($ptrZoneId, $ptrName, RecordType::PTR);

            foreach ($rrsetRecords as $record) {
                $this->recordCommentService->createCommentForRecord(
                    $ptrZoneId,
                    $ptrName,
                    RecordType::PTR,
                    $comment,
                    $record['id'],
                    $userlogin
                );
            }
        }
    }

    /**
     * Sync comment from PTR record to the corresponding A or AAAA records.
     */
    private function syncCommentToARecord(string $content, string $comment, string $userlogin, int $userId, string $forwardType, ?Closure $zoneAllowed): void
    {
        $content = rtrim($content, '.');
        $contentDomainId = $this->domainRepository->getDomainIdByName($content);
        $parts = explode('.', $content);

        while ($contentDomainId === null && count($parts) > 1) {
            array_shift($parts);
            $zoneName = implode('.', $parts);
            $contentDomainId = $this->domainRepository->getDomainIdByName($zoneName);
            if ($contentDomainId !== null) {
                break;
            }
        }

        if ($contentDomainId !== null && $this->mayEditZone($userId, $contentDomainId, $zoneAllowed)) {
            $rrsetRecords = $this->recordRepository->getRRSetRecords($contentDomainId, $content, $forwardType);

            foreach ($rrsetRecords as $record) {
                $this->recordCommentService->createCommentForRecord(
                    $contentDomainId,
                    $content,
                    $forwardType,
                    $comment,
                    $record['id'],
                    $userlogin
                );
            }
        }
    }

    /**
     * The related zone comes from the record content, so it can belong to someone else,
     * or be one where the user must file a change request.
     */
    private function mayEditZone(int $userId, int $zoneId, ?Closure $zoneAllowed): bool
    {
        return $this->permissions->canEditZoneContent($userId, $zoneId, $this->domainRepository->getDomainType($zoneId))
            && $this->approval?->modeForZone($userId, $zoneId) !== ChangeApprovalPolicy::MODE_REQUEST
            && ($zoneAllowed === null || $zoneAllowed($zoneId));
    }
}
