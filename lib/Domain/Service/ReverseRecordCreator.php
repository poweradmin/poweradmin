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

namespace Poweradmin\Domain\Service;

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

class ReverseRecordCreator
{
    private PDOCommon $db;
    private ConfigurationManager $config;
    private LegacyLogger $logger;
    private DnsRecord $dnsRecord;

    public function __construct(
        PDOCommon $db,
        ConfigurationManager $config,
        LegacyLogger $logger,
        DnsRecord $dnsRecord
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->dnsRecord = $dnsRecord;
    }

    public function createReverseRecord($name, $type, $content, string $zone_id, $ttl, $prio, string $comment = '', string $account = ''): array
    {
        $isReverseRecordAllowed = $this->config->get('interface', 'add_reverse_record');

        if (!$name || !$isReverseRecordAllowed) {
            return $this->createErrorResponse('The name is missing or reverse record creation is not allowed.');
        }

        $contentRev = $this->getContentRev($type, $content);
        $zoneRevId = $this->dnsRecord->getBestMatchingZoneIdFromName($contentRev);

        if ($zoneRevId === -1) {
            return $this->createErrorResponse(sprintf(_('There is no matching reverse-zone for: %s.'), $contentRev));
        }

        $zone_name = $this->dnsRecord->getDomainNameById($zone_id);
        $fqdn_name = sprintf("%s.%s", $name, $zone_name);

        // Check for duplicate PTR record before attempting to add
        if ($this->ptrRecordExists($zoneRevId, $contentRev, $fqdn_name)) {
            return $this->createErrorResponse(sprintf(_('A PTR record for %s pointing to %s already exists.'), $contentRev, $fqdn_name));
        }

        $isRecordAdded = $this->addReverseRecord($zone_id, $zoneRevId, $name, $contentRev, $ttl, $prio, $comment, $account);

        if ($isRecordAdded) {
            return $this->createSuccessResponse('Reverse record added');
        }

        return $this->createErrorResponse('Failed to create a reverse record due to an unknown error.');
    }

    public function getContentRev($type, $content): ?string
    {
        if ($type === RecordType::A) {
            $content_array = preg_split("/\./", $content);
            return sprintf("%d.%d.%d.%d.in-addr.arpa", $content_array[3], $content_array[2], $content_array[1], $content_array[0]);
        } elseif ($type === RecordType::AAAA) {
            return DnsRecord::convertIPv6AddrToPtrRec($content);
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

        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        // Look for a PTR record pointing to this name
        $query = "SELECT id, domain_id FROM $records_table 
                  WHERE type = 'PTR' AND name = ? 
                  AND (content = ? OR content LIKE ?)";

        $stmt = $this->db->prepare($query);
        $stmt->execute([$contentRev, $name, "$name.%"]);

        $result = $stmt->fetch();
        if ($result) {
            $recordId = $result['id'];
            $domainId = $result['domain_id'];

            $dnsRecord = new DnsRecord($this->db, $this->config);
            if ($dnsRecord->deleteRecord($recordId)) {
                $dnsRecord->updateSOASerial($domainId);

                if ($this->config->get('dnssec', 'enabled')) {
                    $zone_name = $dnsRecord->getDomainNameById($domainId);
                    $dnssecProvider = DnssecProviderFactory::create($this->db, $this->config);
                    $dnssecProvider->rectifyZone($zone_name);
                }

                return true;
            }
        }

        return false;
    }

    private function addReverseRecord($zone_id, $zone_rev_id, $name, $content_rev, $ttl, $prio, string $comment, string $account): bool
    {
        $zone_name = $this->dnsRecord->getDomainNameById($zone_id);
        $fqdn_name = sprintf("%s.%s", $name, $zone_name);

        // Duplicate check moved to the main createReverseRecord method

        if ($this->dnsRecord->addRecord($zone_rev_id, $content_rev, 'PTR', $fqdn_name, $ttl, $prio)) {
            $this->logger->logInfo(sprintf(
                'client_ip:%s user:%s operation:add_record record_type:PTR record:%s content:%s ttl:%s priority:%s',
                $_SERVER['REMOTE_ADDR'],
                $_SESSION["userlogin"],
                $content_rev,
                $fqdn_name,
                $ttl,
                $prio
            ), $zone_id);

            if ($this->config->get('dnssec', 'enabled')) {
                $dnssecProvider = DnssecProviderFactory::create($this->db, $this->config);
                $dnssecProvider->rectifyZone($zone_name);
            }

            return true;
        }

        return false;
    }

    private function createSuccessResponse(string $message): array
    {
        return [
            'success' => true,
            'type' => 'success',
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
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT COUNT(*) FROM $records_table 
                  WHERE domain_id = :zone_id 
                  AND name = :name 
                  AND type = 'PTR' 
                  AND content = :content";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':zone_id' => $zone_id,
            ':name' => $name,
            ':content' => $content
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
