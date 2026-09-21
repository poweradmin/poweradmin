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

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\DomainUtility;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;

/**
 * Keeps PTR records in step with A/AAAA records: adds, updates and deletes the counterpart in the paired zone.
 */
class ReverseRecordCreator
{
    private ConfigurationInterface $config;
    private AuditLoggerInterface $audit;
    private DomainRepositoryInterface $domainRepository;
    private RecordManagerInterface $recordManager;
    private RecordReadBackendInterface $recordBackend;

    public function __construct(
        ConfigurationInterface $config,
        AuditLoggerInterface $audit,
        DomainRepositoryInterface $domainRepository,
        RecordManagerInterface $recordManager,
        RecordReadBackendInterface $recordBackend
    ) {
        $this->config = $config;
        $this->audit = $audit;
        $this->domainRepository = $domainRepository;
        $this->recordManager = $recordManager;
        $this->recordBackend = $recordBackend;
    }

    public function createReverseRecord($name, $type, $content, int $zone_id, $ttl, $prio, string $comment = '', string $account = ''): array
    {
        $isReverseRecordAllowed = $this->config->get('interface', 'add_reverse_record');

        if (!$name || !$isReverseRecordAllowed) {
            return $this->createErrorResponse('The name is missing or reverse record creation is not allowed.');
        }

        $contentRev = $this->getContentRev($type, $content);
        $zoneRevId = $this->domainRepository->getBestMatchingZoneIdFromName($contentRev);

        if ($zoneRevId === -1) {
            return $this->createErrorResponse(sprintf(_('There is no matching reverse-zone for: %s.'), $contentRev));
        }

        $zone_name = $this->domainRepository->getDomainNameById($zone_id);
        $fqdn_name = DnsHelper::restoreZoneSuffix($name, $zone_name);

        // Check for duplicate PTR record before attempting to add
        if ($this->ptrRecordExists($zoneRevId, $contentRev, $fqdn_name)) {
            return $this->createErrorResponse(sprintf(_('A PTR record for %s pointing to %s already exists.'), $contentRev, $fqdn_name));
        }

        $existingPtrRecords = $this->getExistingPtrRecords($zoneRevId, $contentRev);
        $hasExistingRecords = !empty($existingPtrRecords);

        $written = $this->addReverseRecord($zone_id, $zoneRevId, $name, $contentRev, $ttl, $prio, $comment, $account);

        if ($written->success) {
            if ($hasExistingRecords) {
                // Return success with warning about existing PTR records
                return $this->createWarningResponse(
                    sprintf(
                        _('Reverse record added. Warning: A PTR record for %s already exists pointing to: %s. Having multiple PTR records for the same IP is not recommended.'),
                        $contentRev,
                        implode(', ', $existingPtrRecords)
                    )
                );
            }
            return $this->createSuccessResponse('Reverse record added');
        }

        return $this->createErrorResponse($written->message !== '' ? $written->message : 'Failed to create a reverse record due to an unknown error.');
    }

    public function getContentRev($type, $content): ?string
    {
        if ($type === RecordType::A) {
            $content_array = preg_split("/\./", $content);
            return sprintf("%d.%d.%d.%d.in-addr.arpa", $content_array[3], $content_array[2], $content_array[1], $content_array[0]);
        } elseif ($type === RecordType::AAAA) {
            return DomainUtility::convertIPv6AddrToPtrRec($content);
        }
        return null;
    }

    /**
     * Find and delete the corresponding PTR record for a given A or AAAA record
     *
     * @param string $type Record type (A or AAAA)
     * @param string $content IP address from A/AAAA record
     * @param string $name Hostname from A/AAAA record
     * @return bool True if a matching PTR record was found and deleted
     */
    public function deleteReverseRecord($type, $content, $name): bool
    {
        if ($type !== RecordType::A && $type !== RecordType::AAAA) {
            return false;
        }

        $contentRev = $this->getContentRev($type, $content);
        if ($contentRev === null) {
            return false;
        }

        // PTR content may carry a trailing dot depending on the backend
        $records = $this->recordBackend->findRecordsByName($contentRev, 'PTR');
        foreach ($records as $r) {
            if ($r['content'] === $name || str_starts_with($r['content'], "$name.")) {
                $recordId = $r['id'] ?? 0;
                if (!empty($recordId) && $this->recordManager->deleteRecord($recordId)->success) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sync the PTR record after an A/AAAA forward record was edited.
     * Removes the stale PTR (best-effort; user may not have created one
     * originally) and creates a new PTR for the updated IP and hostname.
     * Returns success when no PTR-relevant fields changed.
     */
    public function updateReverseRecord(
        string $oldType,
        string $oldContent,
        string $oldName,
        string $newType,
        string $newContent,
        string $newName,
        int $zone_id,
        int $ttl,
        int $prio,
        string $comment = '',
        string $account = ''
    ): array {
        $oldIsAddress = $oldType === RecordType::A || $oldType === RecordType::AAAA;
        $newIsAddress = $newType === RecordType::A || $newType === RecordType::AAAA;

        if (!$oldIsAddress && !$newIsAddress) {
            return $this->createSuccessResponse(_('PTR update skipped: record type is not A or AAAA.'));
        }

        // Always delete-then-recreate when sync is requested; this also propagates TTL/priority
        // changes to the PTR even when the address and hostname are unchanged.
        if ($oldIsAddress) {
            $this->deleteReverseRecord($oldType, $oldContent, $oldName);
        }

        if ($newIsAddress) {
            return $this->createReverseRecord($newName, $newType, $newContent, $zone_id, $ttl, $prio, $comment, $account);
        }

        return $this->createSuccessResponse(_('Stale PTR record removed.'));
    }

    /**
     * Find and delete the corresponding A or AAAA record for a given PTR record
     *
     * @param string $ptrName PTR record name (e.g., 1.0.168.192.in-addr.arpa)
     * @param string $ptrContent PTR record content (hostname)
     * @return bool True if a matching A/AAAA record was found and deleted
     */
    public function deleteForwardRecord(string $ptrName, string $ptrContent): bool
    {
        // Extract IP address from PTR name
        $ipAddress = $this->extractIpFromPtrName($ptrName);
        if ($ipAddress === null) {
            return false;
        }

        // Determine record type based on IP address format
        $recordType = filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? RecordType::A : RecordType::AAAA;

        // Remove trailing dot from PTR content if present
        $hostname = rtrim($ptrContent, '.');

        $records = $this->recordBackend->findRecordsByContent($ipAddress, $recordType);
        foreach ($records as $r) {
            if ($r['name'] === $hostname || str_starts_with($r['name'], "$hostname.")) {
                $recordId = $r['id'] ?? 0;
                if (!empty($recordId) && $this->recordManager->deleteRecord($recordId)->success) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract IP address from PTR record name
     *
     * @param string $ptrName PTR record name (e.g., 1.0.168.192.in-addr.arpa or x.x.x.x.ip6.arpa)
     * @return string|null IP address or null if extraction fails
     */
    private function extractIpFromPtrName(string $ptrName): ?string
    {
        // Handle IPv4 PTR records (e.g., 1.0.168.192.in-addr.arpa)
        if (str_ends_with($ptrName, '.in-addr.arpa')) {
            $octets = explode('.', str_replace('.in-addr.arpa', '', $ptrName));
            if (count($octets) === 4) {
                return implode('.', array_reverse($octets));
            }
        }

        // Handle IPv6 PTR records (e.g., b.a.9.8.7.6.5.0.4.0.0.3.2.0.0.1.0.0.0.0.0.0.0.0.0.0.0.0.1.2.3.4.ip6.arpa)
        if (str_ends_with($ptrName, '.ip6.arpa')) {
            $nibbles = explode('.', str_replace('.ip6.arpa', '', $ptrName));
            if (count($nibbles) === 32) {
                $reversedNibbles = array_reverse($nibbles);
                $ipv6 = '';
                for ($i = 0; $i < 32; $i += 4) {
                    if ($i > 0) {
                        $ipv6 .= ':';
                    }
                    $ipv6 .= $reversedNibbles[$i] . $reversedNibbles[$i + 1] . $reversedNibbles[$i + 2] . $reversedNibbles[$i + 3];
                }
                return $ipv6;
            }
        }

        return null;
    }

    private function addReverseRecord(int $zone_id, $zone_rev_id, $name, $content_rev, $ttl, $prio, string $comment, string $account): RecordWriteResult
    {
        $zone_name = $this->domainRepository->getDomainNameById($zone_id);
        $fqdn_name = DnsHelper::restoreZoneSuffix($name, $zone_name);

        // Duplicate check moved to the main createReverseRecord method

        $written = $this->recordManager->addRecordGetId($zone_rev_id, $content_rev, 'PTR', $fqdn_name, $ttl, $prio);
        if ($written->success) {
            $this->audit->logRecordAdd((int)$zone_rev_id, RecordType::PTR, $content_rev, $fqdn_name, $ttl, $prio);
        }

        return $written;
    }

    private function createSuccessResponse(string $message): array
    {
        return [
            'success' => true,
            'type' => 'success',
            'message' => $message,
        ];
    }

    private function createWarningResponse(string $message): array
    {
        return [
            'success' => true,
            'type' => 'warning',
            'message' => $message,
        ];
    }

    private function createErrorResponse(string $message): array
    {
        return [
            'success' => false,
            'type' => 'error',
            'message' => $message,
        ];
    }

    /**
     * Check if an identical PTR record already exists
     *
     * @param int $zone_id Domain ID
     * @param string $name Record name
     * @param string $content Record content
     * @return bool True if identical record exists
     */
    private function ptrRecordExists(int $zone_id, string $name, string $content): bool
    {
        return $this->recordBackend->recordExists($zone_id, $name, 'PTR', $content);
    }

    /**
     * Get all existing PTR records for a given reverse domain name
     *
     * @param int $zone_id Domain ID
     * @param string $name Reverse domain name (e.g., "1.168.192.in-addr.arpa")
     * @return array Array of existing PTR record contents (hostnames)
     */
    private function getExistingPtrRecords(int $zone_id, string $name): array
    {
        $records = $this->recordBackend->getRecordsByZoneId($zone_id, 'PTR');
        $contents = [];
        foreach ($records as $r) {
            if ($r['name'] === $name) {
                $contents[] = $r['content'];
            }
        }
        return $contents;
    }
}
