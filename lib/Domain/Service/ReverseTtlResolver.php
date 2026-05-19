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
use Poweradmin\Domain\Repository\RecordTypeDefaultRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Resolves the default TTL for new records.
 *
 * Fallback chain (first non-null wins):
 *   1. record_type_defaults row for the submitted type (admin-managed via UI)
 *   2. dns.ttl_reverse (legacy config, only when the record is a PTR in a reverse zone)
 *   3. dns.ttl (universal fallback)
 */
class ReverseTtlResolver
{
    public function __construct(
        private ConfigurationManager $config,
        private RecordTypeDefaultRepositoryInterface $recordTypeDefaults,
    ) {
    }

    /**
     * Default TTL for a new record on the add/edit form when the record type
     * is not yet known (only the zone is). Reverse-zone forms default to PTR,
     * so we consult the same fallback chain as resolveTtlForType('PTR', true).
     */
    public function getDefaultTtl(bool $isReverseZone): int
    {
        if ($isReverseZone) {
            $typeDefault = $this->recordTypeDefaults->find(RecordType::PTR);
            if ($typeDefault !== null) {
                return $typeDefault;
            }
            $reverseTtl = $this->getConfiguredReverseTtl();
            if ($reverseTtl !== null) {
                return $reverseTtl;
            }
        }
        return $this->getForwardTtl();
    }

    /**
     * Plain dns.ttl with the 86400 fallback used by the historical batch-PTR
     * flow when settings.defaults.php is bypassed.
     */
    public function getForwardTtl(): int
    {
        return (int)$this->config->get('dns', 'ttl', 86400);
    }

    /**
     * TTL for a PTR that is auto-created alongside a forward record.
     * Same fallback chain as resolveTtlForType('PTR', true), with the
     * forward record's TTL serving as the historical last-resort default.
     */
    public function resolvePtrTtl(int $forwardTtl): int
    {
        $typeDefault = $this->recordTypeDefaults->find(RecordType::PTR);
        if ($typeDefault !== null) {
            return $typeDefault;
        }
        return $this->getConfiguredReverseTtl() ?? $forwardTtl;
    }

    /**
     * TTL for a record about to be persisted. Checks the admin-managed
     * record_type_defaults table first, then the legacy PTR/reverse-zone
     * config fallback, then dns.ttl. The zone-type gate prevents PTRs that
     * legitimately live in forward zones (RFC 2317, custom dns.domain_record_types)
     * from picking up the reverse-zone-only legacy default.
     */
    public function resolveTtlForType(string $recordType, bool $isInReverseZone): int
    {
        $typeKey = strtoupper($recordType);
        $typeDefault = $this->recordTypeDefaults->find($typeKey);
        if ($typeDefault !== null) {
            return $typeDefault;
        }
        if ($isInReverseZone && $typeKey === RecordType::PTR) {
            return $this->getConfiguredReverseTtl() ?? $this->getForwardTtl();
        }
        return $this->getForwardTtl();
    }

    /**
     * The configured dns.ttl_reverse value, or null when unset/empty.
     * Callers use null to signal "preserve historical behavior".
     */
    public function getConfiguredReverseTtl(): ?int
    {
        $reverseTtl = $this->config->get('dns', 'ttl_reverse', null);
        if ($reverseTtl === null || $reverseTtl === '') {
            return null;
        }
        return (int)$reverseTtl;
    }

    /**
     * Admin-configured per-type defaults keyed by uppercase record type.
     * Templates pass this to JS so type-change swaps pick the right value.
     *
     * @return array<string, int>
     */
    public function getTypeDefaults(): array
    {
        return $this->recordTypeDefaults->findAll();
    }
}
