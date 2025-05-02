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

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsValidation\ARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\AAAARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\CSYNCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DSRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HINFORecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\LOCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\MXRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\NSRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SOARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SPFRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\DnsValidation\TXTRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * DNS functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class Dns
{
    private ConfigurationManager $config;
    private PDOLayer $db;
    private MessageService $messageService;
    private TTLValidator $ttlValidator;
    private ARecordValidator $aRecordValidator;
    private AAAARecordValidator $aaaaRecordValidator;
    private CNAMERecordValidator $cnameRecordValidator;
    private CSYNCRecordValidator $csyncRecordValidator;
    private DSRecordValidator $dsRecordValidator;
    private HINFORecordValidator $hinfoRecordValidator;
    private LOCRecordValidator $locRecordValidator;
    private SOARecordValidator $soaRecordValidator;
    private SPFRecordValidator $spfRecordValidator;
    private SRVRecordValidator $srvRecordValidator;
    private TXTRecordValidator $txtRecordValidator;
    private HostnameValidator $hostnameValidator;
    private MXRecordValidator $mxRecordValidator;
    private NSRecordValidator $nsRecordValidator;
    private DnsCommonValidator $dnsCommonValidator;

    public function __construct(PDOLayer $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->ttlValidator = new TTLValidator();
        $this->aRecordValidator = new ARecordValidator($config);
        $this->aaaaRecordValidator = new AAAARecordValidator($config);
        $this->cnameRecordValidator = new CNAMERecordValidator($config, $db);
        $this->csyncRecordValidator = new CSYNCRecordValidator($config);
        $this->dsRecordValidator = new DSRecordValidator($config);
        $this->hinfoRecordValidator = new HINFORecordValidator($config);
        $this->locRecordValidator = new LOCRecordValidator($config);
        $this->soaRecordValidator = new SOARecordValidator($config, $db);
        $this->spfRecordValidator = new SPFRecordValidator($config);
        $this->srvRecordValidator = new SRVRecordValidator($config);
        $this->txtRecordValidator = new TXTRecordValidator($config);
        $this->hostnameValidator = new HostnameValidator($config);
        $this->mxRecordValidator = new MXRecordValidator($config);
        $this->nsRecordValidator = new NSRecordValidator($config);
        $this->dnsCommonValidator = new DnsCommonValidator($db, $config);
    }

    /** Validate DNS record input
     *
     * @param int $rid Record ID
     * @param int $zid Zone ID
     * @param string $type Record Type
     * @param mixed $content content part of record
     * @param string $name Name part of record
     * @param mixed $prio Priority
     * @param mixed $ttl TTL
     *
     * @return array|bool Returns array with validated data on success, false otherwise
     */
    public function validate_input(int $rid, int $zid, string $type, mixed $content, string $name, mixed $prio, mixed $ttl, $dns_hostmaster, $dns_ttl): array|bool
    {
        $dnsRecord = new DnsRecord($this->db, $this->config);
        $zone = $dnsRecord->get_domain_name_by_id($zid);
        if (!$zone) {
            $this->messageService->addSystemError(_('Unable to find domain with the given ID.'));
            return false;
        }

        $cnameValidator = new CNAMERecordValidator($this->config, $this->db);
        if ($type != RecordType::CNAME) {
            if (!$cnameValidator->isValidCnameExistence($name, $rid)) {
                return false;
            }
        }

        switch ($type) {
            case RecordType::A:
                $validationResult = $this->aRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            // TODO: implement validation.
            case RecordType::AFSDB:
            case RecordType::ALIAS:
            case RecordType::APL:
            case RecordType::CAA:
            case RecordType::CDNSKEY:
            case RecordType::CDS:
            case RecordType::CERT:
            case RecordType::DNAME:
            case RecordType::L32:
            case RecordType::L64:
            case RecordType::LUA:
            case RecordType::LP:
            case RecordType::OPENPGPKEY:
            case RecordType::SMIMEA:
            case RecordType::TKEY:
            case RecordType::URI:
            case RecordType::DHCID:
            case RecordType::DLV:
            case RecordType::DNSKEY:
            case RecordType::EUI48:
            case RecordType::EUI64:
            case RecordType::HTTPS:
            case RecordType::IPSECKEY:
            case RecordType::KEY:
            case RecordType::KX:
            case RecordType::MINFO:
            case RecordType::MR:
            case RecordType::NAPTR:
            case RecordType::NID:
            case RecordType::NSEC:
            case RecordType::NSEC3:
            case RecordType::NSEC3PARAM:
            case RecordType::RKEY:
            case RecordType::RP:
            case RecordType::RRSIG:
            case RecordType::SSHFP:
            case RecordType::SVCB:
            case RecordType::TLSA:
            case RecordType::TSIG:
                break;

            case RecordType::AAAA:
                $validationResult = $this->aaaaRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::CNAME:
                $validationResult = $this->cnameRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl, $rid, $zone);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::CSYNC:
                $validationResult = $this->csyncRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::DS:
                $validationResult = $this->dsRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::HINFO:
                $validationResult = $this->hinfoRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::LOC:
                $validationResult = $this->locRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::NS:
                $validationResult = $this->nsRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];

                if (!$this->dnsCommonValidator->isValidNonAliasTarget($content)) {
                    return false;
                }
                break;

            case RecordType::MX:
                $validationResult = $this->mxRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];

                if (!$this->dnsCommonValidator->isValidNonAliasTarget($content)) {
                    return false;
                }
                break;

            case RecordType::PTR:
                $contentHostnameResult = $this->hostnameValidator->isValidHostnameFqdn($content, 0);
                if ($contentHostnameResult === false) {
                    return false;
                }
                $content = $contentHostnameResult['hostname'];

                $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
                if ($hostnameResult === false) {
                    return false;
                }
                $name = $hostnameResult['hostname'];
                break;

            case RecordType::SOA:
                $this->soaRecordValidator->setSOAParams($dns_hostmaster, $zone);
                $validationResult = $this->soaRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::SPF:
                $validationResult = $this->spfRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::SRV:
                $validationResult = $this->srvRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::TXT:
                $validationResult = $this->txtRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            default:
                $this->messageService->addSystemError(_('Unknown record type.'));

                return false;
        }

        // Skip validation if it was already handled by a specific validator
        if (
            $type !== RecordType::A && $type !== RecordType::AAAA && $type !== RecordType::CNAME &&
            $type !== RecordType::CSYNC && $type !== RecordType::MX && $type !== RecordType::NS
        ) {
            $validatedPrio = $this->dnsCommonValidator->isValidPriority($prio, $type);
            if ($validatedPrio === false) {
                return false;
            }

            $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $dns_ttl);
            if ($validatedTtl === false) {
                return false;
            }
        } else {
            // We've already validated these in the specific record validator
            $validatedPrio = $prio;
            $validatedTtl = $ttl;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];
    }
}
