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

namespace Poweradmin\Application\Service\Record;

use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Service\Dns\DomainRecordCreator;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Application\Service\Zone\ChangeApprovalContext;

/**
 * The add-record flow shared by the add-record page, the inline form on the
 * zone editor and the record wizards: open() applies the same gates in the
 * same order, and add() normalises name, content and TTL the same way and
 * follows the same rules for the companion PTR or A record.
 */
class RecordAddService
{
    public function __construct(
        private readonly RecordManagerService $records,
        private readonly ReverseRecordCreator $reverseRecords,
        private readonly DomainRecordCreator $domainRecords,
        private readonly ReverseTtlResolver $ttlResolver,
        private readonly PermissionService $permissions,
        private readonly DomainRepositoryInterface $domains,
        private readonly ChangeApprovalContext $approval
    ) {
    }

    /**
     * Whether the user may add records to the zone directly: the zone must
     * exist, the user's changes must not be routed through review (those go
     * through the zone editor), and the zone must be writable by them.
     */
    public function open(int $zoneId, int $userId): RecordAddAccess
    {
        $zoneName = $this->domains->getDomainNameById($zoneId);
        if ($zoneName === null) {
            return RecordAddAccess::zoneNotFound();
        }
        $zoneType = $this->domains->getDomainType($zoneId);

        if ($this->approval->modeForZone($userId, $zoneId) === ChangeApprovalPolicy::MODE_REQUEST) {
            return RecordAddAccess::refused(RecordAddAccess::REQUIRES_APPROVAL, $zoneName, $zoneType);
        }
        if (!$this->permissions->canEditZoneContent($userId, $zoneId, $zoneType)) {
            return RecordAddAccess::refused(RecordAddAccess::FORBIDDEN, $zoneName, $zoneType);
        }

        return RecordAddAccess::granted($zoneName, $zoneType);
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
