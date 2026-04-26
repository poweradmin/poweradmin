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

use PDO;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

class AuditService
{
    private LegacyLogger $logger;
    private IpAddressRetriever $ipRetriever;

    public function __construct(PDO $db)
    {
        $this->logger = new LegacyLogger($db);
        $this->ipRetriever = new IpAddressRetriever($_SERVER);
    }

    private function getContext(): string
    {
        $username = $_SESSION['userlogin'] ?? 'unknown';
        return sprintf(
            'client_ip:%s user:%s',
            $this->ipRetriever->getClientIp(),
            $username
        );
    }

    public function logPermTemplateChange(string $targetUser, int $oldTemplateId, int $newTemplateId): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:perm_template_change target_user:%s old_template:%d new_template:%d',
            $this->getContext(),
            $targetUser,
            $oldTemplateId,
            $newTemplateId
        ));
    }

    public function logSessionExpired(): void
    {
        $this->logger->logNotice(sprintf(
            '%s operation:session_expired',
            $this->getContext()
        ));
    }

    public function logAccessDenied(string $permission, string $requestUri): void
    {
        $this->logger->logWarn(sprintf(
            '%s operation:access_denied permission:%s uri:%s',
            $this->getContext(),
            $permission,
            $requestUri
        ));
    }

    // Zone ownership

    public function logZoneOwnerAdd(int $zoneId, string $zoneName, int $ownerId): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:zone_owner_add zone:%s owner_id:%d',
            $this->getContext(),
            $zoneName,
            $ownerId
        ), $zoneId);
    }

    public function logZoneOwnerRemove(int $zoneId, string $zoneName, int $ownerId): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:zone_owner_remove zone:%s owner_id:%d',
            $this->getContext(),
            $zoneName,
            $ownerId
        ), $zoneId);
    }

    public function logZoneGroupAdd(int $zoneId, string $zoneName, int $groupId): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:zone_group_add zone:%s group_id:%d',
            $this->getContext(),
            $zoneName,
            $groupId
        ), $zoneId);
    }

    public function logZoneGroupRemove(int $zoneId, string $zoneName, int $groupId): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:zone_group_remove zone:%s group_id:%d',
            $this->getContext(),
            $zoneName,
            $groupId
        ), $zoneId);
    }

    // DNSSEC

    public function logDnssecAddKey(int $zoneId, string $zoneName, string $keyType, string $bits, string $algorithm): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:dnssec_add_key zone:%s key_type:%s bits:%s algorithm:%s',
            $this->getContext(),
            $zoneName,
            $keyType,
            $bits,
            $algorithm
        ), $zoneId);
    }

    public function logDnssecDeleteKey(int $zoneId, string $zoneName, int $keyId): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:dnssec_delete_key zone:%s key_id:%d',
            $this->getContext(),
            $zoneName,
            $keyId
        ), $zoneId);
    }

    public function logDnssecToggleKey(int $zoneId, string $zoneName, int $keyId, string $action): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:dnssec_toggle_key zone:%s key_id:%d action:%s',
            $this->getContext(),
            $zoneName,
            $keyId,
            $action
        ), $zoneId);
    }

    public function logDnssecSignZone(int $zoneId, string $zoneName): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:dnssec_sign_zone zone:%s',
            $this->getContext(),
            $zoneName
        ), $zoneId);
    }

    public function logDnssecUnsignZone(int $zoneId, string $zoneName): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:dnssec_unsign_zone zone:%s',
            $this->getContext(),
            $zoneName
        ), $zoneId);
    }

    // Group membership

    public function logGroupMemberRemove(int $groupId, int $userId): void
    {
        $this->logger->logGroupInfo(sprintf(
            '%s operation:remove_members group_id:%d user_id:%d',
            $this->getContext(),
            $groupId,
            $userId
        ), $groupId);
    }

    // Zone templates

    public function logZoneTemplateAdd(string $templateName): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:add_zone_template template_name:%s',
            $this->getContext(),
            str_replace(' ', '_', $templateName)
        ));
    }

    public function logZoneTemplateEdit(int $templateId, string $templateName): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:edit_zone_template template_id:%d template_name:%s',
            $this->getContext(),
            $templateId,
            str_replace(' ', '_', $templateName)
        ));
    }

    public function logZoneTemplateDelete(int $templateId): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:delete_zone_template template_id:%d',
            $this->getContext(),
            $templateId
        ));
    }

    public function logZoneTemplateUnlink(int $zoneId): void
    {
        $this->logger->logInfo(sprintf(
            '%s operation:unlink_zone_template zone_id:%d',
            $this->getContext(),
            $zoneId
        ), $zoneId);
    }
}
