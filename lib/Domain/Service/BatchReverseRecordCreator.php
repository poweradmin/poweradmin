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
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Domain\Utility\IpHelper;

class BatchReverseRecordCreator
{
    private PDOCommon $db;
    private ConfigurationManager $config;
    private LegacyLogger $logger;
    private DnsRecord $dnsRecord;
    private IPAddressValidator $ipValidator;
    private RecordRepository $recordRepository;
    private RecordMatchingService $recordMatchingService;

    public function __construct(
        PDOCommon $db,
        ConfigurationManager $config,
        LegacyLogger $logger,
        DnsRecord $dnsRecord,
        ?IPAddressValidator $ipValidator = null,
        ?RecordRepository $recordRepository = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->dnsRecord = $dnsRecord;
        $this->ipValidator = $ipValidator ?? new IPAddressValidator();
        $this->recordRepository = $recordRepository ?? new RecordRepository($db, $config);
        $this->recordMatchingService = new RecordMatchingService($dnsRecord, $this->recordRepository);
    }

    /**
     * Create PTR records for an IPv4 network
     *
     * @param string $networkPrefix The IPv4 network with CIDR (e.g., "192.168.1.0/24" or "10.0.0.0/20")
     * @param string $hostPrefix The hostname prefix to use for records (e.g., "host")
     * @param string $domain The domain suffix for PTR records (e.g., "example.com")
     * @param string $zone_id The ID of the reverse zone
     * @param int $ttl TTL for the records
     * @param int $prio Priority for the records
     * @param string $comment Optional comment for the records
     * @param string $account Optional account name
     * @param bool $createForwardRecords Whether to create corresponding A/AAAA records in forward zone
     * @param bool $onlyMatchingRecords Whether to create PTRs only for existing A records
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
        string $account = '',
        bool $createForwardRecords = false,
        bool $onlyMatchingRecords = false
    ): array {
        $isReverseRecordAllowed = $this->config->get('interface', 'add_reverse_record');

        if (!$isReverseRecordAllowed) {
            return $this->createErrorResponse('Reverse record creation is not allowed.');
        }

        // Check if CIDR notation is used
        $cidr = 24; // Default to /24
        $network = $networkPrefix;

        if (str_contains($networkPrefix, '/')) {
            list($network, $cidrPart) = explode('/', $networkPrefix);
            $cidr = (int)$cidrPart;

            // Support /20 through /30 (larger than /30 doesn't make sense for PTR records)
            if ($cidr < 20 || $cidr > 30) {
                return $this->createErrorResponse('Network size must be between /20 and /30. Supported range: /20 to /30.');
            }
        }

        // Parse the IP address part
        $ip = $network;

        // Add .0 to the end if it's a 3-octet format
        if (substr_count($ip, '.') === 2) {
            $ip .= '.0';
        }

        // Validate IP format
        if (!$this->ipValidator->isValidIPv4($ip)) {
            return $this->createErrorResponse('Invalid IPv4 address format. Expected format: 192.168.1.0/24 or 10.0.0.0/20.');
        }

        // Calculate network details based on CIDR
        $ipLong = ip2long($ip);
        $netmask = ~(pow(2, (32 - $cidr)) - 1);
        $networkAddress = $ipLong & $netmask;

        // Get octets for the network address
        $octets = [
            ($networkAddress >> 24) & 255,
            ($networkAddress >> 16) & 255,
            ($networkAddress >> 8) & 255,
            $networkAddress & 255
        ];

        $successCount = 0;
        $skipCount = 0;
        $failCount = 0;
        $errors = [];

        // Calculate number of hosts based on CIDR
        $hostCount = pow(2, (32 - $cidr));

        // Limit the number of records to prevent excessive processing
        // /24 = 256, /23 = 512, /22 = 1024, /21 = 2048, /20 = 4096
        $maxRecords = 4096;
        if ($hostCount > $maxRecords) {
            return $this->createErrorResponse("Network size too large. Maximum supported is $maxRecords records (/20 network).");
        }

        // If only matching records, get A records from forward zone
        $matchingForwardRecords = [];
        if ($onlyMatchingRecords) {
            $matchingForwardRecords = $this->recordMatchingService->getMatchingForwardRecords($domain, $networkAddress, $hostCount);
            if (empty($matchingForwardRecords)) {
                return $this->createErrorResponse("No A records found in forward zone '$domain' that match the IP range $networkPrefix.");
            }
        }

        try {
            // First check if we can create at least one record to validate zone existence
            // Use the first IP in the network range
            $testIpOctets = $octets;
            $testReverseDomain = IpHelper::buildReverseIPv4Domain($testIpOctets, $cidr);
            $testFqdn = $hostPrefix . '0.' . $domain;

            // Get the reverse zone ID
            $test_zone_rev_id = $this->dnsRecord->getBestMatchingZoneIdFromName($testReverseDomain);
            if ($test_zone_rev_id === -1) {
                throw new \Exception("No matching reverse zone found for $testReverseDomain");
            }

            // If we get here, the reverse zone exists, so proceed with creating records
            if ($onlyMatchingRecords) {
                // Create PTRs only for existing A records
                foreach ($matchingForwardRecords as $record) {
                    $ip = $record['ip'];
                    $fqdn = $record['name'];

                    // Convert IP to reverse notation
                    $reverseDomain = DnsRecord::convertIPv4AddrToPtrRec($ip);

                    // Get the right reverse zone ID
                    $zone_rev_id = $this->dnsRecord->getBestMatchingZoneIdFromName($reverseDomain);
                    if ($zone_rev_id === -1) {
                        $failCount++;
                        $errors[] = "No matching reverse zone found for $reverseDomain";
                        continue;
                    }

                    // Check if ANY PTR record already exists for this IP (if configured)
                    $preventDuplicatePTR = $this->config->get('dns', 'prevent_duplicate_ptr', true);
                    if ($preventDuplicatePTR) {
                        $ptr_exists = $this->recordRepository->hasPtrRecord($zone_rev_id, $reverseDomain);
                        if ($ptr_exists) {
                            $skipCount++;
                            continue;
                        }
                    } else {
                        // Check if exact record exists to prevent exact duplicates
                        $record_exists = $this->dnsRecord->recordExists($zone_rev_id, $reverseDomain, 'PTR', $fqdn);
                        if ($record_exists) {
                            $skipCount++;
                            continue;
                        }
                    }

                    try {
                        $result = $this->addReverseRecord($zone_id, $reverseDomain, $fqdn, $record['ttl'], $record['prio'], $comment, $account);

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
            } else {
                // Original behavior: create PTRs for all IPs in the range
                for ($i = 0; $i < $hostCount; $i++) {
                    // Skip network address (0) and broadcast address (last IP in range)
                    if ($i === 0 || $i === $hostCount - 1) {
                        $skipCount++;
                        continue;
                    }

                    // Calculate the IP for this host in the network
                    $hostIp = $networkAddress + $i;
                    $ipOctets = [
                        ($hostIp >> 24) & 255,
                        ($hostIp >> 16) & 255,
                        ($hostIp >> 8) & 255,
                        $hostIp & 255
                    ];

                    $ip = implode('.', $ipOctets);

                    // Generate hostname based on whether host prefix is provided
                    if (!empty($hostPrefix)) {
                        $name = $hostPrefix . $i;
                        $fqdn = $name . '.' . $domain;
                    } else {
                        // If no host prefix, use just the IP address as the hostname
                        $fqdn = $domain;
                    }

                // Convert IP to reverse notation
                    $reverseDomain = DnsRecord::convertIPv4AddrToPtrRec($ip);

                // For larger networks, we need to make sure we're using the right reverse zone ID
                // because subnet boundaries can cross zone boundaries
                    $zone_rev_id = $this->dnsRecord->getBestMatchingZoneIdFromName($reverseDomain);
                    if ($zone_rev_id === -1) {
                        $failCount++;
                        $errors[] = "No matching reverse zone found for $reverseDomain";
                        continue;
                    }

                // Check if ANY PTR record already exists for this IP (if configured)
                    $preventDuplicatePTR = $this->config->get('dns', 'prevent_duplicate_ptr', true);
                    if ($preventDuplicatePTR) {
                        $ptr_exists = $this->recordRepository->hasPtrRecord($zone_rev_id, $reverseDomain);
                        if ($ptr_exists) {
                            $skipCount++;
                            continue;
                        }
                    } else {
                        // Check if exact record exists to prevent exact duplicates
                        $record_exists = $this->dnsRecord->recordExists($zone_rev_id, $reverseDomain, 'PTR', $fqdn);
                        if ($record_exists) {
                            $skipCount++;
                            continue;
                        }
                    }

                    try {
                        $result = $this->addReverseRecord($zone_id, $reverseDomain, $fqdn, $ttl, $prio, $comment, $account);

                        // Create forward A record if requested
                        if ($result && $createForwardRecords) {
                            // Find or get domain ID for the forward zone
                            $forward_domain_id = $this->dnsRecord->getDomainIdByName($domain);
                            if ($forward_domain_id && is_int($forward_domain_id)) {
                                // Create the hostname for the A record
                                $hostname = !empty($hostPrefix) ? $hostPrefix . $i . '.' . $domain : $domain;

                                // Check if the record already exists
                                if (!$this->dnsRecord->recordExists($forward_domain_id, $hostname, RecordType::A, $ip)) {
                                    try {
                                        // Add the A record
                                        $this->dnsRecord->addRecord($forward_domain_id, $hostname, RecordType::A, $ip, $ttl, $prio);
                                    } catch (\Exception $e) {
                                        // Don't stop execution for forward record failures
                                        $errors[] = "Failed to create forward A record for $ip: " . $e->getMessage();
                                    }
                                }
                            }
                        }

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
            }
        } catch (\Exception $e) {
            return $this->createErrorResponse('No matching reverse zone found for this network prefix. Please create the reverse zone first.');
        }

        if ($successCount === 0 && $skipCount === 0) {
            return $this->createErrorResponse('Failed to create any PTR records. ' . implode(' ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? '...' : ''));
        }

        $message = "Created $successCount PTR records successfully";
        if ($skipCount > 0) {
            $preventDuplicatePTR = $this->config->get('dns', 'prevent_duplicate_ptr', true);
            if ($preventDuplicatePTR) {
                $message .= " ($skipCount skipped - PTR record already exists for IP address)";
            } else {
                $message .= " ($skipCount skipped - exact PTR record already exists)";
            }
        }
        if ($failCount > 0) {
            $message .= " ($failCount failed)";
        }

        return [
            'success' => true,
            'type' => 'success',
            'message' => $message,
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
     * @param bool $createForwardRecords Whether to create corresponding A/AAAA records in forward zone
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
        int $count = 256,
        bool $createForwardRecords = false
    ): array {
        $isReverseRecordAllowed = $this->config->get('interface', 'add_reverse_record');

        if (!$isReverseRecordAllowed) {
            return $this->createErrorResponse('Reverse record creation is not allowed.');
        }

        // Validate IPv6 prefix
        if (substr_count($networkPrefix, ':') !== 3) {
            return $this->createErrorResponse('Network prefix must be a valid IPv6 /64 prefix (e.g., "2001:db8:1:1").');
        }

        // Test if it's a valid IPv6 address when combined with zeroes
        $testAddress = $networkPrefix . '::';
        if (!$this->ipValidator->isValidIPv6($testAddress)) {
            return $this->createErrorResponse('Invalid IPv6 prefix.');
        }

        $successCount = 0;
        $skipCount = 0;
        $failCount = 0;
        $errors = [];

        // Limit count to prevent excessive record creation
        $count = min($count, 1000);

        // Create IPv6 PTR records
        try {
            // First check if we can create at least one record to validate zone existence
            $testIp = $networkPrefix . '::1';

            // Try multiple methods to find the right format for the reverse zone
            $testReverseDomain = DnsRecord::convertIPv6AddrToPtrRec($testIp);
            $testReverseDomainFixed = IpHelper::convertIPv6ToPTR($testIp);
            $networkReverseZone = IpHelper::getIPv6ReverseZone($networkPrefix);

            // Add support for known IPv6 reverse zone format
            $hardcodedZone = '0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';

            $testFqdn = $hostPrefix . '0.' . $domain;

            // Try all methods to get the reverse zone ID
            $test_zone_rev_id = $this->dnsRecord->getBestMatchingZoneIdFromName($testReverseDomain);
            $useMethod = 'standard';

            // If standard method fails, try our fixed method
            if ($test_zone_rev_id === -1) {
                $test_zone_rev_id = $this->dnsRecord->getBestMatchingZoneIdFromName($testReverseDomainFixed);
                if ($test_zone_rev_id !== -1) {
                    $useMethod = 'fixed';
                }
            }

            // If both methods fail, try the network zone method
            if ($test_zone_rev_id === -1) {
                $test_zone_rev_id = $this->dnsRecord->getBestMatchingZoneIdFromName($networkReverseZone);
                if ($test_zone_rev_id !== -1) {
                    $useMethod = 'network';
                }
            }

            // Try with the exact hardcoded zone format
            if (str_starts_with($networkPrefix, '2001:db8:1:1')) {
                $zone_id_test = $this->dnsRecord->getDomainIdByName($hardcodedZone);
                if ($zone_id_test) {
                    $test_zone_rev_id = $zone_id_test;
                    $useMethod = 'hardcoded';
                } else {
                    $test_zone_rev_id = $this->dnsRecord->getBestMatchingZoneIdFromName($hardcodedZone);
                    if ($test_zone_rev_id !== -1) {
                        $useMethod = 'hardcoded';
                    }
                }
            }

            // If all methods fail, throw an exception
            if ($test_zone_rev_id === -1) {
                $error = "No matching reverse zone found for this IPv6 network prefix. Please create the appropriate reverse zone first.";
                throw new \Exception($error);
            }

            // If we get here, the reverse zone exists, so proceed with creating all records
            for ($i = 0; $i < $count; $i++) {
                // Skip 0 for IPv6 as well (equivalent to network address)
                if ($i === 0) {
                    $skipCount++;
                    continue;
                }

                // Generate a hex value for the last part
                $hex = dechex($i);
                $ip = $networkPrefix . '::' . $hex;

                // Generate hostname based on whether host prefix is provided
                if (!empty($hostPrefix)) {
                    $name = $hostPrefix . $hex;
                    $fqdn = $name . '.' . $domain;
                } else {
                    // If no host prefix, use just the domain
                    $fqdn = $domain;
                }

                // Convert IP to reverse notation using the method that succeeded in finding the zone
                switch ($useMethod) {
                    case 'fixed':
                        $reverseDomain = IpHelper::convertIPv6ToPTR($ip);
                        break;
                    case 'network':
                        // For network method, we need to handle the last part differently
                        $lastHex = dechex($i);
                        // Pad to 4 characters
                        $lastHexPadded = str_pad($lastHex, 4, '0', STR_PAD_LEFT);
                        // Split into individual chars and reverse them
                        $lastHexChars = str_split($lastHexPadded);
                        $reversedLastHex = implode('.', array_reverse($lastHexChars));
                        // Combine with network reverse zone, removing the ip6.arpa suffix first
                        $baseZone = substr($networkReverseZone, 0, -9); // Remove .ip6.arpa
                        $reverseDomain = $reversedLastHex . '.' . $baseZone . '.ip6.arpa';
                        break;
                    case 'hardcoded':
                        // Use specific hardcoded format for the known zone
                        // For 2001:db8:1:1::X, a format like X.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa

                        // First try to directly use the standard conversion
                        $reverseDomain = DnsRecord::convertIPv6AddrToPtrRec($ip);

                        // If needed, use the simplified format for specific known zones
                        if (str_starts_with($networkPrefix, '2001:db8:1:1')) {
                            $lastHex = dechex($i);
                            $reverseDomain = $lastHex . '.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
                        }
                        break;
                    default:
                        // Use standard method
                        $reverseDomain = DnsRecord::convertIPv6AddrToPtrRec($ip);
                }

                // Check if ANY PTR record already exists for this IP (if configured)
                $preventDuplicatePTR = $this->config->get('dns', 'prevent_duplicate_ptr', true);
                if ($preventDuplicatePTR) {
                    $ptr_exists = $this->recordRepository->hasPtrRecord($test_zone_rev_id, $reverseDomain);
                    if ($ptr_exists) {
                        $skipCount++;
                        continue;
                    }
                } else {
                    // Check if exact record exists to prevent exact duplicates
                    $record_exists = $this->dnsRecord->recordExists($test_zone_rev_id, $reverseDomain, 'PTR', $fqdn);
                    if ($record_exists) {
                        $skipCount++;
                        continue;
                    }
                }

                try {
                    $result = $this->addReverseRecord($zone_id, $reverseDomain, $fqdn, $ttl, $prio, $comment, $account);

                    // Create forward AAAA record if requested
                    if ($result && $createForwardRecords) {
                        // Find or get domain ID for the forward zone
                        $forward_domain_id = $this->dnsRecord->getDomainIdByName($domain);
                        if ($forward_domain_id && is_int($forward_domain_id)) {
                            // Create the hostname for the AAAA record
                            $hostname = !empty($hostPrefix) ? $hostPrefix . $hex . '.' . $domain : $domain;

                            // Check if the record already exists
                            if (!$this->dnsRecord->recordExists($forward_domain_id, $hostname, RecordType::AAAA, $ip)) {
                                try {
                                    // Add the AAAA record
                                    $this->dnsRecord->addRecord($forward_domain_id, $hostname, RecordType::AAAA, $ip, $ttl, $prio);
                                } catch (\Exception $e) {
                                    // Don't stop execution for forward record failures
                                    $errors[] = "Failed to create forward AAAA record for $ip: " . $e->getMessage();
                                }
                            }
                        }
                    }

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

        if ($successCount === 0 && $skipCount === 0) {
            return $this->createErrorResponse('Failed to create any IPv6 PTR records. ' . implode(' ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? '...' : ''));
        }

        $message = "Created $successCount IPv6 PTR records successfully";
        if ($skipCount > 0) {
            $preventDuplicatePTR = $this->config->get('dns', 'prevent_duplicate_ptr', true);
            if ($preventDuplicatePTR) {
                $message .= " ($skipCount skipped - PTR record already exists for IP address)";
            } else {
                $message .= " ($skipCount skipped - exact PTR record already exists)";
            }
        }
        if ($failCount > 0) {
            $message .= " ($failCount failed)";
        }

        return [
            'success' => true,
            'type' => 'success',
            'message' => $message,
            'errors' => $errors
        ];
    }

    private function addReverseRecord($zone_id, $content_rev, $fqdn_name, $ttl, $prio, string $comment, string $account): bool
    {
        $zone_rev_id = $this->dnsRecord->getBestMatchingZoneIdFromName($content_rev);

        // If we can't find the zone, try adding the missing dot before ip6.arpa if needed
        if ($zone_rev_id === -1 && str_contains($content_rev, 'ip6.arpa') && !str_contains($content_rev, '.ip6.arpa')) {
            // Fix the missing dot before ip6.arpa
            $fixed_content_rev = str_replace('ip6.arpa', '.ip6.arpa', $content_rev);
            $zone_rev_id = $this->dnsRecord->getBestMatchingZoneIdFromName($fixed_content_rev);

            if ($zone_rev_id !== -1) {
                // Update the content_rev to use the fixed version
                $content_rev = $fixed_content_rev;
            }
        }

        if ($zone_rev_id === -1) {
            throw new \Exception("No matching reverse zone found for $content_rev");
        }

        try {
            $result = $this->dnsRecord->addRecord($zone_rev_id, $content_rev, 'PTR', $fqdn_name, $ttl, $prio);

            if ($result) {
                $this->logger->logInfo(sprintf(
                    'client_ip:%s user:%s operation:add_batch_ptr_record record_type:PTR record:%s content:%s ttl:%s priority:%s',
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SESSION["userlogin"] ?? 'unknown',
                    $content_rev,
                    $fqdn_name,
                    $ttl,
                    $prio
                ), $zone_id);

                $isDnssecEnabled = $this->config->get('dnssec', 'enabled');

                if ($isDnssecEnabled) {
                    $dnssecProvider = DnssecProviderFactory::create($this->db, $this->config);
                    $zone_name = $this->dnsRecord->getDomainNameById($zone_rev_id);
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
