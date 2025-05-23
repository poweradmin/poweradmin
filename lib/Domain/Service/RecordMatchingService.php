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

use Poweradmin\Domain\Repository\RecordRepository;

class RecordMatchingService
{
    private DnsRecord $dnsRecord;
    private RecordRepository $recordRepository;

    public function __construct(DnsRecord $dnsRecord, RecordRepository $recordRepository)
    {
        $this->dnsRecord = $dnsRecord;
        $this->recordRepository = $recordRepository;
    }

    /**
     * Get A records from the forward zone that match the given IP range
     *
     * @param string $domain The forward zone domain name
     * @param int $networkAddress The network address as a long integer
     * @param int $hostCount The number of hosts in the network
     * @return array Array of matching A records with their hostnames and IPs
     */
    public function getMatchingForwardRecords(string $domain, int $networkAddress, int $hostCount): array
    {
        $forward_domain_id = $this->dnsRecord->getDomainIdByName($domain);
        if (!$forward_domain_id || !is_int($forward_domain_id)) {
            return [];
        }

        // Get all A records from the forward zone
        $aRecords = $this->recordRepository->getRecordsByDomainId($forward_domain_id, 'A');

        $matchingRecords = [];
        $broadcastAddress = $networkAddress + $hostCount - 1;

        foreach ($aRecords as $record) {
            // Convert the IP address to long for comparison
            $ipLong = ip2long($record['content']);

            // Check if the IP is within the network range
            if ($ipLong !== false && $ipLong >= $networkAddress && $ipLong <= $broadcastAddress) {
                $matchingRecords[] = [
                    'name' => $record['name'],
                    'ip' => $record['content'],
                    'ttl' => $record['ttl'],
                    'prio' => $record['prio']
                ];
            }
        }

        return $matchingRecords;
    }

    /**
     * Get AAAA records from the forward zone that match the given IPv6 network
     *
     * @param string $domain The forward zone domain name
     * @param string $networkPrefix The IPv6 network prefix
     * @return array Array of matching AAAA records
     */
    public function getMatchingIPv6ForwardRecords(string $domain, string $networkPrefix): array
    {
        $forward_domain_id = $this->dnsRecord->getDomainIdByName($domain);
        if (!$forward_domain_id || !is_int($forward_domain_id)) {
            return [];
        }

        // Get all AAAA records from the forward zone
        $aaaaRecords = $this->recordRepository->getRecordsByDomainId($forward_domain_id, 'AAAA');

        $matchingRecords = [];

        foreach ($aaaaRecords as $record) {
            // Check if the IPv6 address is within the network prefix
            if (str_starts_with($record['content'], $networkPrefix)) {
                $matchingRecords[] = [
                    'name' => $record['name'],
                    'ip' => $record['content'],
                    'ttl' => $record['ttl'],
                    'prio' => $record['prio']
                ];
            }
        }

        return $matchingRecords;
    }
}
