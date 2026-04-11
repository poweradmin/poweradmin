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
use Poweradmin\Infrastructure\Utility\UserAgentService;

class AuditService
{
    private LegacyLogger $logger;
    private IpAddressRetriever $ipRetriever;
    private UserAgentService $userAgentService;

    public function __construct(PDO $db)
    {
        $this->logger = new LegacyLogger($db);
        $this->ipRetriever = new IpAddressRetriever($_SERVER);
        $this->userAgentService = new UserAgentService($_SERVER);
    }

    private function getContext(): string
    {
        $username = $_SESSION['userlogin'] ?? 'unknown';
        return sprintf(
            'client_ip:%s user:%s browser:%s',
            $this->ipRetriever->getClientIp(),
            $username,
            $this->userAgentService->getBrowserInfo()
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
}
