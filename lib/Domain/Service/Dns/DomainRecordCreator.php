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

namespace Poweradmin\Domain\Service\Dns;

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\IpHelper;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\AuditLoggerInterface;

/**
 * Adds the matching A record in the managed forward zone when a PTR record is created in a reverse zone.
 */
class DomainRecordCreator
{
    private ConfigurationInterface $config;
    private DomainRepositoryInterface $domainRepository;
    private RecordManagerInterface $recordManager;
    private IPAddressValidator $ipValidator;
    private ?ReverseTtlResolver $reverseTtlResolver;
    private ?AuditLoggerInterface $audit;

    private const IPV4_SUFFIX = '.in-addr.arpa';
    private const IPV6_SUFFIX = '.ip6.arpa';

    public function __construct(
        ConfigurationInterface $config,
        DomainRepositoryInterface $domainRepository,
        RecordManagerInterface $recordManager,
        ?IPAddressValidator $ipValidator = null,
        ?ReverseTtlResolver $reverseTtlResolver = null,
        ?AuditLoggerInterface $audit = null,
    ) {
        $this->config = $config;
        $this->domainRepository = $domainRepository;
        $this->recordManager = $recordManager;
        $this->ipValidator = $ipValidator ?? new IPAddressValidator();
        $this->reverseTtlResolver = $reverseTtlResolver;
        $this->audit = $audit;
    }

    /**
     * Create a matching A/AAAA record in the forward zone when adding a PTR record.
     *
     * @param string $name PTR record name - accepts both relative ("55") and FQDN ("55.2.0.192.in-addr.arpa")
     * @param string $type Record type (must be "PTR")
     * @param string $content Forward hostname the PTR points to (e.g. "host.example.com")
     * @param int $zone_id Reverse zone ID
     * @param string $comment Optional record comment
     * @param string $account Optional account name for logging
     */
    public function addDomainRecord(string $name, string $type, string $content, int $zone_id, string $comment = '', string $account = ''): array
    {
        $iface_add_domain_record = $this->config->get('interface', 'add_domain_record');

        // Walk up the hostname hierarchy to find the managed forward zone
        $domainId = $this->findManagedZoneId($content);
        if ($domainId === null) {
            return $this->errorResponse(sprintf(_('There is no managed zone for domain: %s.'), $content));
        }

        if ($name && $iface_add_domain_record && $type === 'PTR') {
            $zone_name = strtolower((string)$this->domainRepository->getDomainNameById($zone_id));
            $name = strtolower($name);

            // Strip reverse zone suffix if caller passed FQDN instead of relative name
            if (str_ends_with($name, self::IPV4_SUFFIX) || str_ends_with($name, self::IPV6_SUFFIX)) {
                $name = DnsHelper::stripZoneSuffix($name, $zone_name);
            }

            // A PTR at the apex of a /32 or /128 zone: its first label is the last address part
            $addressZone = $zone_name;
            if ($name === '@' && str_contains($zone_name, '.')) {
                [$name, $addressZone] = explode('.', $zone_name, 2);
            }

            if (str_ends_with($zone_name, self::IPV4_SUFFIX)) {
                return $this->processIPv4($name, $addressZone, $content, $domainId, $comment, $account);
            }

            if (str_ends_with($zone_name, self::IPV6_SUFFIX)) {
                return $this->processIPv6($name, $addressZone, $content, $domainId, $comment, $account);
            }
        }

        return $this->errorResponse(_('This domain record was not valid and could not be added.'));
    }

    private function processIPv4(string $name, string $zone_name, string $content, int $domainId, string $comment, string $account): array
    {
        $proposedIP = IpHelper::getProposedIPv4($name, $zone_name, self::IPV4_SUFFIX);
        if ($proposedIP && $this->ipValidator->isValidIPv4($proposedIP)) {
            return $this->addRecord($domainId, $content, RecordType::A, $proposedIP);
        }
        return $this->errorResponse(_('This domain record was not valid and could not be added.'));
    }

    private function processIPv6(string $name, string $zone_name, string $content, int $domainId, string $comment, string $account): array
    {
        $proposedIP = IpHelper::getProposedIPv6($name, $zone_name, self::IPV6_SUFFIX);
        if ($proposedIP && $this->ipValidator->isValidIPv6($proposedIP)) {
            return $this->addRecord($domainId, $content, RecordType::AAAA, $proposedIP);
        }
        return $this->errorResponse(_('This domain record was not valid and could not be added.'));
    }

    private function addRecord(int $domainId, string $content, string $type, string $proposedIP): array
    {
        // Get the actual zone name so we can derive the correct hostname
        $zoneName = $this->domainRepository->getDomainNameById($domainId);
        $domainName = DnsHelper::stripZoneSuffix(rtrim($content, '.'), $zoneName);
        if ($domainName === '@') {
            $domainName = rtrim($content, '.');
        }
        $ttl = $this->reverseTtlResolver !== null
            ? $this->reverseTtlResolver->resolveTtlForType($type, false)
            : $this->config->get('dns', 'ttl');
        $result = $this->recordManager->addRecordGetId($domainId, $domainName, $type, $proposedIP, $ttl, 0);

        if ($result->success) {
            $this->audit?->logRecordAdd($domainId, $type, rtrim($content, '.'), $proposedIP, $ttl, 0);

            return [
                'success' => true,
                'type' => 'success',
                'message' => _('The domain record was successfully added.')
            ];
        }

        return $this->errorResponse((string)$result->message);
    }

    /**
     * Walk up the hostname hierarchy to find the best matching managed zone.
     * For "test.sub.example.com", tries: test.sub.example.com (a zone apex), sub.example.com, then example.com.
     */
    private function findManagedZoneId(string $hostname): ?int
    {
        $parts = explode('.', rtrim($hostname, '.'));
        for ($i = 0; $i < count($parts); $i++) {
            $candidate = implode('.', array_slice($parts, $i));
            $domainId = $this->domainRepository->getDomainIdByName($candidate);
            if ($domainId !== null) {
                return $domainId;
            }
        }
        return null;
    }

    private function errorResponse(string $message): array
    {
        return [
            'success' => false,
            'type' => 'error',
            'message' => $message
        ];
    }
}
