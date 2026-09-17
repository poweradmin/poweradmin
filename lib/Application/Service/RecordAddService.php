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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DomainRecordCreator;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Domain\Utility\DnsHelper;

/**
 * The add-record flow shared by the add-record page and the inline form on the
 * zone editor: name and content are normalised the same way, the TTL default is
 * resolved the same way, and the companion PTR or A record follows the same rules.
 */
class RecordAddService
{
    public function __construct(
        private readonly RecordManagerService $records,
        private readonly ReverseRecordCreator $reverseRecords,
        private readonly DomainRecordCreator $domainRecords,
        private readonly ReverseTtlResolver $ttlResolver,
        private readonly PermissionService $permissions,
        private readonly DomainRepositoryInterface $domains
    ) {
    }

    /**
     * @param int|null $ttl The submitted TTL, or null for the configured default of the type
     * @param string $companion One of the RecordAddResult::COMPANION_* constants, or '' for none
     * @param int $disabled 1 to create the record disabled (API callers); the forms always pass 0
     */
    public function add(
        int $zoneId,
        string $zoneName,
        string $name,
        string $type,
        string $content,
        ?int $ttl,
        int $prio,
        string $comment,
        int $userId,
        string $username,
        string $companion = '',
        int $disabled = 0
    ): RecordAddResult {
        $ttl ??= $this->ttlResolver->resolveTtlForType($type, DnsHelper::isReverseZoneName($zoneName));
        $name = DnsHelper::restoreZoneSuffix(DnsIdnService::toPunycode($name), $zoneName);
        $content = DnsIdnService::convertContentToPunycode($type, $content);

        // Callers gate the page, but the write defends itself: edit level, zone
        // read-only state and the own_as_client type restriction are re-checked here
        if (!$this->permissions->canEditZoneRecord($userId, $zoneId, $type, $this->domains->getDomainType($zoneId), $name, $zoneName)) {
            return RecordAddResult::refused(RecordWriteResult::forbidden(
                _('You do not have the permission to add a record to this zone.')
            ));
        }

        $written = $this->records->createRecord($zoneId, $name, $type, $content, $ttl, $prio, $comment, $username, $disabled);
        if (!$written->success) {
            return RecordAddResult::refused($written);
        }

        if ($companion === RecordAddResult::COMPANION_PTR) {
            // A configured dns.ttl_reverse always wins for the PTR; otherwise it inherits the forward TTL
            $ptr = $this->reverseRecords->createReverseRecord($name, $type, $content, $zoneId, $this->ttlResolver->resolvePtrTtl($ttl), $prio, $comment, $username);

            return new RecordAddResult(
                $written,
                RecordAddResult::COMPANION_PTR,
                !empty($ptr['success']),
                ($ptr['type'] ?? '') === 'warning',
                isset($ptr['message']) ? (string)$ptr['message'] : null
            );
        }

        if ($companion === RecordAddResult::COMPANION_A) {
            $a = $this->domainRecords->addDomainRecord($name, $type, $content, $zoneId, $comment, $username);

            return new RecordAddResult(
                $written,
                RecordAddResult::COMPANION_A,
                !empty($a['success']),
                false,
                empty($a['success']) && isset($a['message']) ? (string)$a['message'] : null
            );
        }

        return new RecordAddResult($written);
    }
}
