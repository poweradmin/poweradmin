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

use Closure;
use Exception;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Model\ReverseNetwork;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\DnssecProviderInterface;
use Poweradmin\Domain\Utility\IpHelper;
use Poweradmin\Domain\Utility\DomainUtility;

/**
 * Creates PTR records for a whole IPv4 or IPv6 /64 network, optionally only where forward A/AAAA records exist.
 */
class BatchReverseRecordCreator
{
    private ConfigurationInterface $config;
    private AuditLoggerInterface $audit;
    private DomainRepositoryInterface $domainRepository;
    private RecordManagerInterface $recordManager;
    private IPAddressValidator $ipValidator;
    private RecordRepositoryInterface $recordRepository;
    private RecordMatchingService $recordMatchingService;
    private Closure $dnssecProvider;
    private ?DnssecProviderInterface $builtDnssecProvider = null;

    /**
     * @param Closure(): DnssecProviderInterface $dnssecProvider Built on first use, so DNSSEC-disabled installs never construct one
     */
    public function __construct(
        ConfigurationInterface $config,
        AuditLoggerInterface $audit,
        DomainRepositoryInterface $domainRepository,
        RecordRepositoryInterface $recordRepository,
        RecordManagerInterface $recordManager,
        Closure $dnssecProvider,
        ?IPAddressValidator $ipValidator = null
    ) {
        $this->config = $config;
        $this->audit = $audit;
        $this->domainRepository = $domainRepository;
        $this->recordManager = $recordManager;
        $this->ipValidator = $ipValidator ?? new IPAddressValidator();
        $this->recordRepository = $recordRepository;
        $this->recordMatchingService = new RecordMatchingService($domainRepository, $this->recordRepository);
        $this->dnssecProvider = $dnssecProvider;
    }

    /**
     * Create PTR records for an IPv4 network
     *
     * @param string $networkPrefix The IPv4 network with CIDR (e.g., "192.168.1.0/24" or "10.0.0.0/20")
     * @param string $hostPrefix The hostname prefix to use for records (e.g., "host")
     * @param string $domain The domain suffix for PTR records (e.g., "example.com")
     * @param string $zone_id The ID of the reverse zone
     * @param int $ttl TTL for the PTR records
     * @param int $prio Priority for the records
     * @param string $comment Optional comment for the records
     * @param string $account Optional account name
     * @param bool $createForwardRecords Whether to create corresponding A/AAAA records in forward zone
     * @param bool $onlyMatchingRecords Whether to create PTRs only for existing A records
     * @param int|null $forwardTtl Optional separate TTL for the auto-created forward A records; falls back to $ttl when null
     * @param int|null $matchingPtrTtl Optional override for the PTR TTL in matching-records mode; when null, the matched A record's TTL is used (historical behavior)
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
        bool $onlyMatchingRecords = false,
        ?int $forwardTtl = null,
        ?int $matchingPtrTtl = null
    ): array {
        if (!$this->config->get('interface', 'add_reverse_record')) {
            return $this->createErrorResponse('Reverse record creation is not allowed.');
        }

        $network = $this->parseIPv4Network($networkPrefix);
        if (is_array($network)) {
            return $network;
        }

        $matchingForwardRecords = [];
        if ($onlyMatchingRecords) {
            $matchingForwardRecords = $this->collectMatchingForwardRecords($domain, $network, false);
            if (empty($matchingForwardRecords)) {
                return $this->createErrorResponse("No A records found in forward zone '$domain' that match the IP range $networkPrefix.");
            }
        }

        $tally = self::newTally();

        try {
            if ($this->domainRepository->getBestMatchingZoneIdFromName($network->probeReverseName) === -1) {
                throw new Exception("No matching reverse zone found for {$network->probeReverseName}");
            }

            if ($onlyMatchingRecords) {
                $this->createPtrsForMatchingRecords($matchingForwardRecords, $zone_id, $matchingPtrTtl, $comment, $account, false, $tally);
            } else {
                for ($i = 0; $i < $network->hostCount; $i++) {
                    // Skip network address (0) and broadcast address (last IP in range)
                    if ($i === 0 || $i === $network->hostCount - 1) {
                        $tally['skip']++;
                        continue;
                    }

                    $ip = long2ip($network->networkAddress + $i);
                    $fqdn = !empty($hostPrefix) ? $hostPrefix . $i . '.' . $domain : $domain;

                    $this->createPtrForHost(
                        null,
                        DomainUtility::convertIPv4AddrToPtrRec($ip),
                        $ip,
                        $fqdn,
                        $zone_id,
                        $ttl,
                        $prio,
                        $comment,
                        $account,
                        $createForwardRecords ? RecordType::A : null,
                        $domain,
                        $forwardTtl ?? $ttl,
                        $tally
                    );
                }
            }
        } catch (Exception $e) {
            return $this->createErrorResponse('No matching reverse zone found for this network prefix. Please create the reverse zone first.');
        }

        return $this->summariseBatch($tally, 'PTR');
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
     * @param int|null $forwardTtl Optional separate TTL for the auto-created forward AAAA records; falls back to $ttl when null
     * @param bool $onlyMatchingRecords Whether to create PTRs only for existing AAAA records
     * @param int|null $matchingPtrTtl Optional override for the PTR TTL in matching-records mode; when null, the matched AAAA record's TTL is used
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
        bool $createForwardRecords = false,
        ?int $forwardTtl = null,
        bool $onlyMatchingRecords = false,
        ?int $matchingPtrTtl = null
    ): array {
        if (!$this->config->get('interface', 'add_reverse_record')) {
            return $this->createErrorResponse('Reverse record creation is not allowed.');
        }

        $network = $this->parseIPv6Network($networkPrefix, $count);
        if (is_array($network)) {
            return $network;
        }

        $matchingForwardRecords = [];
        if ($onlyMatchingRecords) {
            $matchingForwardRecords = $this->collectMatchingForwardRecords($domain, $network, true);
            if (empty($matchingForwardRecords)) {
                return $this->createErrorResponse("No AAAA records found in forward zone '$domain' that match the IPv6 prefix $networkPrefix.");
            }
        }

        $tally = self::newTally();

        try {
            $zoneRevId = $this->domainRepository->getBestMatchingZoneIdFromName($network->probeReverseName);
            if ($zoneRevId === -1) {
                throw new Exception("No matching reverse zone found for this IPv6 network prefix. Please create the appropriate reverse zone first.");
            }

            if ($onlyMatchingRecords) {
                $this->createPtrsForMatchingRecords($matchingForwardRecords, $zone_id, $matchingPtrTtl, $comment, $account, true, $tally);
            } else {
                for ($i = 0; $i < $network->hostCount; $i++) {
                    // Skip 0 for IPv6 as well (equivalent to network address)
                    if ($i === 0) {
                        $tally['skip']++;
                        continue;
                    }

                    $hex = dechex($i);
                    $ip = $network->prefix . '::' . $hex;
                    $fqdn = !empty($hostPrefix) ? $hostPrefix . $hex . '.' . $domain : $domain;

                    // The whole /64 lives in one reverse zone, so the probe lookup is reused for every host
                    $this->createPtrForHost(
                        $zoneRevId,
                        DomainUtility::convertIPv6AddrToPtrRec($ip),
                        $ip,
                        $fqdn,
                        $zone_id,
                        $ttl,
                        $prio,
                        $comment,
                        $account,
                        $createForwardRecords ? RecordType::AAAA : null,
                        $domain,
                        $forwardTtl ?? $ttl,
                        $tally
                    );
                }
            }
        } catch (Exception $e) {
            return $this->createErrorResponse('No matching reverse zone found for this IPv6 network prefix. Please create the reverse zone first.');
        }

        return $this->summariseBatch($tally, 'IPv6 PTR');
    }

    /**
     * Parse "a.b.c.d/nn" (or the three-octet "a.b.c" shorthand meaning /24) into a network, or an error response.
     */
    private function parseIPv4Network(string $networkPrefix): ReverseNetwork|array
    {
        $cidr = 24;
        $ip = $networkPrefix;

        if (str_contains($networkPrefix, '/')) {
            list($ip, $cidrPart) = explode('/', $networkPrefix);
            $cidr = (int)$cidrPart;

            // Larger than /30 makes no sense for PTR records; smaller than /20 exceeds the record cap
            if ($cidr < 20 || $cidr > 30) {
                return $this->createErrorResponse('Network size must be between /20 and /30. Supported range: /20 to /30.');
            }
        }

        if (substr_count($ip, '.') === 2) {
            $ip .= '.0';
        }

        if (!$this->ipValidator->isValidIPv4($ip)) {
            return $this->createErrorResponse('Invalid IPv4 address format. Expected format: 192.168.1.0/24 or 10.0.0.0/20.');
        }

        $networkAddress = ip2long($ip) & IpHelper::getCidrNetmask($cidr);
        $hostCount = IpHelper::getCidrBlockSize($cidr);

        $maxRecords = 4096;
        if ($hostCount > $maxRecords) {
            return $this->createErrorResponse("Network size too large. Maximum supported is $maxRecords records (/20 network).");
        }

        $octets = [
            ($networkAddress >> 24) & 255,
            ($networkAddress >> 16) & 255,
            ($networkAddress >> 8) & 255,
            $networkAddress & 255
        ];

        return new ReverseNetwork(
            long2ip($networkAddress),
            $networkAddress,
            $hostCount,
            IpHelper::buildReverseIPv4Domain($octets, $cidr)
        );
    }

    /**
     * Parse a four-hextet /64 prefix into a network capped at 1000 hosts, or an error response.
     */
    private function parseIPv6Network(string $networkPrefix, int $count): ReverseNetwork|array
    {
        if (substr_count($networkPrefix, ':') !== 3) {
            return $this->createErrorResponse('Network prefix must be a valid IPv6 /64 prefix (e.g., "2001:db8:1:1").');
        }

        if (!$this->ipValidator->isValidIPv6($networkPrefix . '::')) {
            return $this->createErrorResponse('Invalid IPv6 prefix.');
        }

        return new ReverseNetwork(
            $networkPrefix,
            0,
            min($count, 1000),
            DomainUtility::convertIPv6AddrToPtrRec($networkPrefix . '::1')
        );
    }

    /**
     * Forward A or AAAA records of $domain whose address falls inside the network.
     */
    private function collectMatchingForwardRecords(string $domain, ReverseNetwork $network, bool $ipv6): array
    {
        if ($ipv6) {
            return $this->recordMatchingService->getMatchingIPv6ForwardRecords($domain, $network->prefix);
        }

        return $this->recordMatchingService->getMatchingForwardRecords($domain, $network->networkAddress, $network->hostCount);
    }

    /**
     * Matching mode: one PTR per forward record, pointing back at the record's own name with its TTL and priority.
     */
    private function createPtrsForMatchingRecords(
        array $forwardRecords,
        string $zone_id,
        ?int $matchingPtrTtl,
        string $comment,
        string $account,
        bool $ipv6,
        array &$tally
    ): void {
        foreach ($forwardRecords as $record) {
            $ip = $record['ip'];
            $reverseDomain = $ipv6 ? DomainUtility::convertIPv6AddrToPtrRec($ip) : DomainUtility::convertIPv4AddrToPtrRec($ip);

            $this->createPtrForHost(
                null,
                $reverseDomain,
                $ip,
                $record['name'],
                $zone_id,
                $matchingPtrTtl ?? $record['ttl'],
                $record['prio'],
                $comment,
                $account,
                null,
                '',
                0,
                $tally
            );
        }
    }

    /**
     * Create one PTR (and optionally its forward record) and record the outcome in the tally.
     *
     * @param int|null $zoneRevId Reverse zone to check for duplicates, or null to resolve it from $reverseDomain
     * @param string|null $forwardType A or AAAA to also create the forward record in $domain, null to skip it
     */
    private function createPtrForHost(
        ?int $zoneRevId,
        string $reverseDomain,
        string $ip,
        string $fqdn,
        string $zone_id,
        int $ttl,
        int $prio,
        string $comment,
        string $account,
        ?string $forwardType,
        string $domain,
        int $forwardTtl,
        array &$tally
    ): void {
        if ($zoneRevId === null) {
            // Subnet boundaries can cross reverse zone boundaries, so each host resolves its own zone
            $zoneRevId = $this->domainRepository->getBestMatchingZoneIdFromName($reverseDomain);
            if ($zoneRevId === -1) {
                $tally['fail']++;
                $tally['errors'][] = "No matching reverse zone found for $reverseDomain";
                return;
            }
        }

        if ($this->config->get('dns', 'prevent_duplicate_ptr', true)) {
            $exists = $this->recordRepository->hasPtrRecord($zoneRevId, $reverseDomain);
        } else {
            $exists = $this->recordRepository->recordExists($zoneRevId, $reverseDomain, 'PTR', $fqdn);
        }
        if ($exists) {
            $tally['skip']++;
            return;
        }

        try {
            $result = $this->addReverseRecord($zone_id, $reverseDomain, $fqdn, $ttl, $prio, $comment, $account);

            if ($result && $forwardType !== null) {
                $this->createForwardRecord($domain, $fqdn, $forwardType, $ip, $forwardTtl, $prio, $tally);
            }

            if ($result) {
                $tally['success']++;
            } else {
                $tally['fail']++;
                $tally['errors'][] = "Failed to create PTR record for $ip";
            }
        } catch (Exception $e) {
            $tally['fail']++;
            $tally['errors'][] = "Failed to create PTR record for $ip: " . $e->getMessage();
        }
    }

    /**
     * Add the forward A/AAAA record unless it already exists; a failure is logged but never aborts the batch.
     */
    private function createForwardRecord(string $domain, string $hostname, string $type, string $ip, int $ttl, int $prio, array &$tally): void
    {
        $forwardDomainId = $this->domainRepository->getDomainIdByName($domain);
        if (!$forwardDomainId) {
            return;
        }

        if ($this->recordRepository->recordExists($forwardDomainId, $hostname, $type, $ip)) {
            return;
        }

        try {
            $this->recordManager->addRecord($forwardDomainId, $hostname, $type, $ip, $ttl, $prio);
        } catch (Exception $e) {
            $tally['errors'][] = "Failed to create forward $type record for $ip: " . $e->getMessage();
        }
    }

    /**
     * @return array{success: int, skip: int, fail: int, errors: string[]}
     */
    private static function newTally(): array
    {
        return ['success' => 0, 'skip' => 0, 'fail' => 0, 'errors' => []];
    }

    /**
     * Turn the tally into the result payload; nothing created and nothing skipped counts as a failed batch.
     *
     * @param array{success: int, skip: int, fail: int, errors: string[]} $tally
     * @param string $label "PTR" or "IPv6 PTR", as shown in the summary message
     */
    private function summariseBatch(array $tally, string $label): array
    {
        $errors = $tally['errors'];

        if ($tally['success'] === 0 && $tally['skip'] === 0) {
            return $this->createErrorResponse("Failed to create any $label records. " . implode(' ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? '...' : ''));
        }

        $message = "Created {$tally['success']} $label records successfully";
        if ($tally['skip'] > 0) {
            if ($this->config->get('dns', 'prevent_duplicate_ptr', true)) {
                $message .= " ({$tally['skip']} skipped - PTR record already exists for IP address)";
            } else {
                $message .= " ({$tally['skip']} skipped - exact PTR record already exists)";
            }
        }
        if ($tally['fail'] > 0) {
            $message .= " ({$tally['fail']} failed)";
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
        $zone_rev_id = $this->domainRepository->getBestMatchingZoneIdFromName($content_rev);

        // If we can't find the zone, try adding the missing dot before ip6.arpa if needed
        if ($zone_rev_id === -1 && str_contains($content_rev, 'ip6.arpa') && !str_contains($content_rev, '.ip6.arpa')) {
            // Fix the missing dot before ip6.arpa
            $fixed_content_rev = str_replace('ip6.arpa', '.ip6.arpa', $content_rev);
            $zone_rev_id = $this->domainRepository->getBestMatchingZoneIdFromName($fixed_content_rev);

            if ($zone_rev_id !== -1) {
                // Update the content_rev to use the fixed version
                $content_rev = $fixed_content_rev;
            }
        }

        if ($zone_rev_id === -1) {
            throw new Exception("No matching reverse zone found for $content_rev");
        }

        try {
            $result = $this->recordManager->addRecord($zone_rev_id, $content_rev, 'PTR', $fqdn_name, $ttl, $prio);

            if ($result) {
                $this->audit->logBatchPtrRecordAdd($zone_rev_id, $content_rev, $fqdn_name, $ttl, $prio);

                $isDnssecEnabled = $this->config->get('dnssec', 'enabled');

                if ($isDnssecEnabled) {
                    $this->builtDnssecProvider ??= ($this->dnssecProvider)();
                    $zone_name = $this->domainRepository->getDomainNameById($zone_rev_id);
                    $this->builtDnssecProvider->rectifyZone($zone_name);
                }

                return true;
            } else {
                return false;
            }
        } catch (Exception $e) {
            throw $e;
        }
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
