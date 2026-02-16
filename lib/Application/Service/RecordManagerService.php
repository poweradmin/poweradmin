<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

class RecordManagerService
{
    private PDOCommon $db;
    private DnsRecord $dnsRecord;
    private RecordCommentService $recordCommentService;
    private RecordCommentSyncService $commentSyncService;
    private LegacyLogger $logger;
    private ConfigurationManager $config;

    public function __construct(
        PDOCommon $db,
        DnsRecord $dnsRecord,
        RecordCommentService $recordCommentService,
        RecordCommentSyncService $commentSyncService,
        LegacyLogger $logger,
        ConfigurationManager $config
    ) {
        $this->db = $db;
        $this->dnsRecord = $dnsRecord;
        $this->recordCommentService = $recordCommentService;
        $this->commentSyncService = $commentSyncService;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function createRecord(int $zone_id, string $name, string $type, string $content, int $ttl, int $prio, string $comment, string $userlogin, string $clientIp): bool
    {
        $zone_name = $this->dnsRecord->getDomainNameById($zone_id);

        // Use addRecordGetId to get the newly created record ID directly
        $recordId = $this->dnsRecord->addRecordGetId($zone_id, $name, $type, $content, $ttl, $prio);
        if ($recordId === null) {
            return false;
        }

        $this->logRecordCreation($clientIp, $userlogin, $type, $name, $zone_name, $content, $ttl, $prio, $zone_id);
        $this->handleDnssec($zone_name);
        $this->handleCommentsWithId($zone_id, $name, $type, $content, $comment, $userlogin, $zone_name, $recordId);

        return true;
    }

    private function logRecordCreation(string $clientIp, string $userlogin, string $type, string $name, string $zone_name, string $content, int $ttl, int $prio, string $zone_id): void
    {
        $this->logger->logInfo(sprintf(
            'client_ip:%s user:%s operation:add_record record_type:%s record:%s.%s content:%s ttl:%s priority:%s',
            $clientIp,
            $userlogin,
            $type,
            $name,
            $zone_name,
            $content,
            $ttl,
            $prio
        ), $zone_id);
    }

    private function handleDnssec(string $zone_name): void
    {
        if ($this->config->get('dnssec', 'enabled')) {
            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->config);
            $dnssecProvider->rectifyZone($zone_name);
        }
    }

    /**
     * Handle comments when record ID is already known (from addRecordGetId).
     * This avoids the need to look up the record ID after creation.
     * Uses the record_comment_links table for per-record comment linking.
     */
    private function handleCommentsWithId(int $zoneId, string $name, string $type, string $content, string $comment, string $userLogin, string $zone_name, int $recordId): void
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
        if ($this->config->get('misc', 'record_comments_sync')) {
            $this->handleSyncedComments($zoneId, $name, $type, $content, $comment, $userLogin, $fullZoneName);
        }
    }

    private function handleComments(int $zoneId, string $name, string $type, string $content, string $comment, string $userLogin, string $zone_name, ?int $prio = null, ?int $ttl = null): void
    {
        if ($comment === '') {
            return;
        }

        $fullZoneName = DnsHelper::restoreZoneSuffix($name, $zone_name);

        // Get record ID for per-record comment linking (via linking table)
        // Pass prio and ttl for deterministic lookup (important for MX, SRV records with same content)
        $recordRepository = new RecordRepository($this->db, $this->config);
        $recordId = $recordRepository->getRecordId($zoneId, strtolower($fullZoneName), $type, $content, $prio, $ttl);

        if ($recordId !== null) {
            // Use per-record comment (linked by record ID via linking table)
            $this->recordCommentService->createCommentForRecord(
                $zoneId,
                $fullZoneName,
                $type,
                $comment,
                $recordId,
                $userLogin
            );
        } else {
            // Fallback to legacy RRset-based comment if record ID not found
            $this->recordCommentService->createComment(
                $zoneId,
                $fullZoneName,
                $type,
                $comment,
                $userLogin
            );
        }

        // Handle synced comments separately (for PTR records, etc.)
        if ($this->config->get('misc', 'record_comments_sync')) {
            $this->handleSyncedComments($zoneId, $name, $type, $content, $comment, $userLogin, $fullZoneName);
        }
    }

    /**
     * Sync comments to related records (A/AAAA <-> PTR).
     * This only syncs to the TARGET record, not the source record
     * (which already has a per-record comment from handleCommentsWithId).
     */
    private function handleSyncedComments(int $zone_id, string $name, string $type, string $content, string $comment, string $userlogin, string $full_name): void
    {
        if ($type === RecordType::A || $type === RecordType::AAAA) {
            // Sync comment to the corresponding PTR record
            $this->syncCommentToPtrRecord($type, $content, $comment, $userlogin);
        } elseif ($type === 'PTR') {
            // Sync comment to the corresponding A record
            $this->syncCommentToARecord($content, $comment, $userlogin);
        }
        // For other record types, no sync needed - per-record comment is already set
    }

    /**
     * Sync comment from A/AAAA record to corresponding PTR record.
     */
    private function syncCommentToPtrRecord(string $type, string $content, string $comment, string $userlogin): void
    {
        $ptrName = $type === RecordType::A
            ? DnsRecord::convertIPv4AddrToPtrRec($content)
            : DnsRecord::convertIPv6AddrToPtrRec($content);

        $ptrZoneId = $this->dnsRecord->getBestMatchingZoneIdFromName($ptrName);
        if ($ptrZoneId !== -1) {
            $recordRepository = new RecordRepository($this->db, $this->config);
            $rrsetRecords = $recordRepository->getRRSetRecords($ptrZoneId, $ptrName, RecordType::PTR);

            foreach ($rrsetRecords as $record) {
                $this->recordCommentService->createCommentForRecord(
                    $ptrZoneId,
                    $ptrName,
                    RecordType::PTR,
                    $comment,
                    (int)$record['id'],
                    $userlogin
                );
            }
        }
    }

    /**
     * Sync comment from PTR record to corresponding A record.
     */
    private function syncCommentToARecord(string $content, string $comment, string $userlogin): void
    {
        $content = rtrim($content, '.');
        $contentDomainId = null;
        $parts = explode('.', $content);

        while (count($parts) > 1) {
            array_shift($parts);
            $zoneName = implode('.', $parts);
            $contentDomainId = $this->dnsRecord->getDomainIdByName($zoneName);
            if ($contentDomainId !== null) {
                break;
            }
        }

        if ($contentDomainId !== null) {
            $recordRepository = new RecordRepository($this->db, $this->config);
            $rrsetRecords = $recordRepository->getRRSetRecords($contentDomainId, $content, RecordType::A);

            foreach ($rrsetRecords as $record) {
                $this->recordCommentService->createCommentForRecord(
                    $contentDomainId,
                    $content,
                    RecordType::A,
                    $comment,
                    (int)$record['id'],
                    $userlogin
                );
            }
        }
    }
}
