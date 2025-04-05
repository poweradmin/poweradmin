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

use Poweradmin\AppConfiguration;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

class BatchReverseRecordCreator
{
    private PDOLayer $db;
    private AppConfiguration $config;
    private LegacyLogger $logger;
    private DnsRecord $dnsRecord;
    private ReverseRecordCreator $reverseRecordCreator;
    private ConfigurationManager $configManager;

    public function __construct(
        PDOLayer $db,
        AppConfiguration $config,
        LegacyLogger $logger,
        DnsRecord $dnsRecord,
        ReverseRecordCreator $reverseRecordCreator
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->dnsRecord = $dnsRecord;
        $this->reverseRecordCreator = $reverseRecordCreator;
        $this->configManager = ConfigurationManager::getInstance();
    }

    /**
     * Create PTR records for an IPv4 /24 network
     *
     * @param string $networkPrefix The first 3 octets of the IPv4 address (e.g., "192.168.1")
     * @param string $hostPrefix The hostname prefix to use for records (e.g., "host")
     * @param string $domain The domain suffix for PTR records (e.g., "example.com")
     * @param string $zone_id The ID of the reverse zone
     * @param int $ttl TTL for the records
     * @param int $prio Priority for the records
     * @param string $comment Optional comment for the records
     * @param string $account Optional account name
     *
     * @return array Result of the operation
     */
    public function createIPv4Network(
        string $networkPrefix,
        string $hostPrefix,
        string $domain,
        string $zone_id,
        int $ttl,
        int $prio = 0,
        string $comment = '',
        string $account = ''
    ): array {
        $isReverseRecordAllowed = $this->configManager->get('interface', 'add_reverse_record');

        if (!$isReverseRecordAllowed) {
            return $this->createErrorResponse('Reverse record creation is not allowed.');
        }

        // Count the octets in the prefix
        $octetCount = substr_count($networkPrefix, '.') + 1;
        
        if ($octetCount !== 3) {
            return $this->createErrorResponse('Network prefix must consist of 3 octets (e.g., "192.168.1").');
        }

        // Validate network prefix format
        $octets = explode('.', $networkPrefix);
        
        foreach ($octets as $octet) {
            if (!is_numeric($octet) || $octet < 0 || $octet > 255) {
                return $this->createErrorResponse('Invalid network prefix. Each octet must be between 0 and 255.');
            }
        }

        $successCount = 0;
        $failCount = 0;
        $errors = [];

        // Create 256 PTR records (0-255)
        try {
            // First check if we can create at least one record to validate zone existence
            $testIp = $networkPrefix . '.0';
            $testReverseDomain = DnsRecord::convert_ipv4addr_to_ptrrec($testIp);
            $testFqdn = $hostPrefix . '-0.' . $domain;
            
            // Test if the reverse zone exists for this network
            $this->addReverseRecord($zone_id, $testReverseDomain, $testFqdn, $ttl, $prio, $comment, $account);
            
            // If we get here, the reverse zone exists, so proceed with creating all records
            for ($i = 0; $i < 256; $i++) {
                $ip = $networkPrefix . '.' . $i;
                $name = $hostPrefix . '-' . $i;
                $fqdn = $name . '.' . $domain;

                // Convert IP to reverse notation
                $reverseDomain = DnsRecord::convert_ipv4addr_to_ptrrec($ip);
                try {
                    $result = $this->addReverseRecord($zone_id, $reverseDomain, $fqdn, $ttl, $prio, $comment, $account);
                    if ($result) {
                        $successCount++;
                    } else {
                        $failCount++;
                        $errors[] = "Failed to create PTR record for $ip";
                    }
                } catch (\Exception $e) {
                    $failCount++;
                    $errors[] = "Failed to create PTR record for $ip: " . $e->getMessage();
                }
            }
        } catch (\Exception $e) {
            return $this->createErrorResponse('No matching reverse zone found for this network prefix. Please create the reverse zone first.');
        }

        if ($successCount === 0) {
            return $this->createErrorResponse('Failed to create any PTR records. ' . implode(' ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? '...' : ''));
        }

        return [
            'success' => true,
            'type' => 'success',
            'message' => "Created $successCount PTR records successfully" . ($failCount > 0 ? " ($failCount failed)" : ""),
            'errors' => $errors
        ];
    }

    /**
     * Create PTR records for an IPv6 /64 network
     *
     * @param string $networkPrefix The IPv6 /64 prefix (e.g., "2001:db8:1:1")
     * @param string $hostPrefix The hostname prefix to use for records (e.g., "host")
     * @param string $domain The domain suffix for PTR records (e.g., "example.com")
     * @param string $zone_id The ID of the reverse zone
     * @param int $ttl TTL for the records
     * @param int $prio Priority for the records
     * @param string $comment Optional comment for the records
     * @param string $account Optional account name
     * @param int $count Number of records to create (default 256)
     *
     * @return array Result of the operation
     */
    public function createIPv6Network(
        string $networkPrefix,
        string $hostPrefix,
        string $domain,
        string $zone_id,
        int $ttl,
        int $prio = 0,
        string $comment = '',
        string $account = '',
        int $count = 256
    ): array {
        $isReverseRecordAllowed = $this->configManager->get('interface', 'add_reverse_record');

        if (!$isReverseRecordAllowed) {
            return $this->createErrorResponse('Reverse record creation is not allowed.');
        }

        // Validate IPv6 prefix
        if (substr_count($networkPrefix, ':') !== 3) {
            return $this->createErrorResponse('Network prefix must be a valid IPv6 /64 prefix (e.g., "2001:db8:1:1").');
        }

        // Test if it's a valid IPv6 address when combined with zeroes
        $testAddress = $networkPrefix . '::';
        if (!filter_var($testAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->createErrorResponse('Invalid IPv6 prefix.');
        }

        $successCount = 0;
        $failCount = 0;
        $errors = [];

        // Limit count to prevent excessive record creation
        $count = min($count, 1000);

        // Create IPv6 PTR records
        try {
            // First check if we can create at least one record to validate zone existence
            $testIp = $networkPrefix . '::0';
            $testReverseDomain = DnsRecord::convert_ipv6addr_to_ptrrec($testIp);
            $testFqdn = $hostPrefix . '-0.' . $domain;
            
            // Test if the reverse zone exists for this network
            $this->addReverseRecord($zone_id, $testReverseDomain, $testFqdn, $ttl, $prio, $comment, $account);
            
            // If we get here, the reverse zone exists, so proceed with creating all records
            for ($i = 0; $i < $count; $i++) {
                // Generate a hex value for the last part
                $hex = dechex($i);
                $ip = $networkPrefix . '::' . $hex;
                $name = $hostPrefix . '-' . $hex;
                $fqdn = $name . '.' . $domain;

                // Convert IP to reverse notation
                $reverseDomain = DnsRecord::convert_ipv6addr_to_ptrrec($ip);
                try {
                    $result = $this->addReverseRecord($zone_id, $reverseDomain, $fqdn, $ttl, $prio, $comment, $account);
                    if ($result) {
                        $successCount++;
                    } else {
                        $failCount++;
                        $errors[] = "Failed to create PTR record for $ip";
                    }
                } catch (\Exception $e) {
                    $failCount++;
                    $errors[] = "Failed to create PTR record for $ip: " . $e->getMessage();
                }
            }
        } catch (\Exception $e) {
            return $this->createErrorResponse('No matching reverse zone found for this IPv6 network prefix. Please create the reverse zone first.');
        }

        if ($successCount === 0) {
            return $this->createErrorResponse('Failed to create any IPv6 PTR records. ' . implode(' ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? '...' : ''));
        }

        return [
            'success' => true,
            'type' => 'success',
            'message' => "Created $successCount IPv6 PTR records successfully" . ($failCount > 0 ? " ($failCount failed)" : ""),
            'errors' => $errors
        ];
    }

    private function addReverseRecord($zone_id, $content_rev, $fqdn_name, $ttl, $prio, string $comment, string $account): bool
    {
        $zone_rev_id = $this->dnsRecord->get_best_matching_zone_id_from_name($content_rev);

        if ($zone_rev_id === -1) {
            throw new \Exception("No matching reverse zone found for $content_rev");
        }

        try {
            $result = $this->dnsRecord->add_record($zone_rev_id, $content_rev, 'PTR', $fqdn_name, $ttl, $prio);
            
            if ($result) {
                $this->logger->log_info(sprintf(
                    'client_ip:%s user:%s operation:add_batch_ptr_record record_type:PTR record:%s content:%s ttl:%s priority:%s',
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SESSION["userlogin"] ?? 'unknown',
                    $content_rev,
                    $fqdn_name,
                    $ttl,
                    $prio
                ), $zone_id);

                $isDnssecEnabled = $this->configManager->get('dnssec', 'enabled');
                
                if ($isDnssecEnabled) {
                    $dnssecProvider = DnssecProviderFactory::create($this->db, $this->config);
                    $zone_name = $this->dnsRecord->get_domain_name_by_id($zone_rev_id);
                    $dnssecProvider->rectifyZone($zone_name);
                }

                return true;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            throw $e;
        }
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
}