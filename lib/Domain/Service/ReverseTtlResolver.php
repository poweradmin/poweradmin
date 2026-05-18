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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Resolves the default TTL for new records, preferring dns.ttl_reverse when
 * applicable and falling back to dns.ttl when the reverse default is unset.
 */
class ReverseTtlResolver
{
    public function __construct(private ConfigurationManager $config)
    {
    }

    /**
     * Default TTL for a new record on the add/edit form.
     * Returns dns.ttl_reverse for reverse zones when configured, otherwise dns.ttl.
     */
    public function getDefaultTtl(bool $isReverseZone): int
    {
        $reverseTtl = $this->getConfiguredReverseTtl();
        if ($isReverseZone && $reverseTtl !== null) {
            return $reverseTtl;
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
     * When dns.ttl_reverse is configured it always wins; otherwise the PTR
     * inherits the forward record's TTL (historical behavior).
     */
    public function resolvePtrTtl(int $forwardTtl): int
    {
        return $this->getConfiguredReverseTtl() ?? $forwardTtl;
    }

    /**
     * TTL for a record about to be persisted. PTR records in reverse zones prefer
     * dns.ttl_reverse (falling back to dns.ttl); every other case uses dns.ttl.
     * The zone-type gate is required so PTRs that legitimately live in forward
     * zones (RFC 2317 setups, custom dns.domain_record_types) keep dns.ttl.
     */
    public function resolveTtlForType(string $recordType, bool $isInReverseZone): int
    {
        if ($isInReverseZone && strtoupper($recordType) === RecordType::PTR) {
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
}
