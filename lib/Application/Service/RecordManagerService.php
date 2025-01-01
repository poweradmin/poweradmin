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

use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

class RecordManagerService
{
    private PDOLayer $db;
    private DnsRecord $dnsRecord;
    private RecordCommentService $recordCommentService;
    private RecordCommentSyncService $commentSyncService;
    private LegacyLogger $logger;
    private AppConfiguration $config;

    public function __construct(
        PDOLayer $db,
        DnsRecord $dnsRecord,
        RecordCommentService $recordCommentService,
        RecordCommentSyncService $commentSyncService,
        LegacyLogger $logger,
        AppConfiguration $config
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
        $zone_name = $this->dnsRecord->get_domain_name_by_id($zone_id);
        if (!$this->dnsRecord->add_record($zone_id, $name, $type, $content, $ttl, $prio)) {
            return false;
        }

        $this->logRecordCreation($clientIp, $userlogin, $type, $name, $zone_name, $content, $ttl, $prio, $zone_id);
        $this->handleDnssec($zone_name);
        $this->handleComments($zone_id, $name, $type, $content, $comment, $userlogin, $zone_name);

        return true;
    }

    private function logRecordCreation(string $clientIp, string $userlogin, string $type, string $name, string $zone_name, string $content, int $ttl, int $prio, string $zone_id): void
    {
        $this->logger->log_info(sprintf(
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
        if ($this->config->get('pdnssec_use')) {
            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->config);
            $dnssecProvider->rectifyZone($zone_name);
        }
    }

    private function handleComments(int $zoneId, string $name, string $type, string $content, string $comment, string $userLogin, string $zone_name): void
    {
        $fullZoneName = "$name.$zone_name";

        if ($this->config->get('record_comments_sync')) {
            $this->handleSyncedComments($zoneId, $name, $type, $content, $comment, $userLogin, $fullZoneName);
        } else {
            $this->recordCommentService->createComment(
                $zoneId,
                $fullZoneName,
                $type,
                $comment,
                $userLogin
            );
        }
    }

    private function handleSyncedComments(int $zone_id, string $name, string $type, string $content, string $comment, string $userlogin, string $full_name): void
    {
        if ($type === 'A' || $type === 'AAAA') {
            $this->handleForwardRecordComments($zone_id, $name, $type, $content, $comment, $userlogin, $full_name);
        } elseif ($type === 'PTR') {
            $this->handlePtrRecordComments($zone_id, $content, $comment, $userlogin, $full_name);
        } else {
            $this->recordCommentService->createComment(
                $zone_id,
                $full_name,
                $type,
                $comment,
                $userlogin
            );
        }
    }

    private function handleForwardRecordComments(int $zone_id, string $name, string $type, string $content, string $comment, string $userlogin, string $full_name): void
    {
        $ptrName = $type === 'A'
            ? DnsRecord::convert_ipv4addr_to_ptrrec($content)
            : DnsRecord::convert_ipv6addr_to_ptrrec($content);

        $ptrZoneId = $this->dnsRecord->get_best_matching_zone_id_from_name($ptrName);
        if ($ptrZoneId !== -1) {
            $this->commentSyncService->syncCommentsForPtrRecord(
                $zone_id,
                $ptrZoneId,
                $full_name,
                $ptrName,
                $comment,
                $userlogin
            );
        } else {
            $this->recordCommentService->createComment(
                $zone_id,
                $full_name,
                $type,
                $comment,
                $userlogin
            );
        }
    }

    private function handlePtrRecordComments(int $ptrZoneId, string $content, string $comment, string $userlogin, string $full_name): void
    {
        $domainName = DnsHelper::getRegisteredDomain($content);
        $contentDomainId = $this->dnsRecord->get_domain_id_by_name($domainName);
        if ($contentDomainId !== false) {
            $this->commentSyncService->syncCommentsForDomainRecord(
                $contentDomainId,
                $ptrZoneId,
                $content,
                $full_name,
                $comment,
                $userlogin
            );
        } else {
            $this->recordCommentService->createComment(
                $ptrZoneId,
                $full_name,
                'PTR',
                $comment,
                $userlogin
            );
        }
    }
}
