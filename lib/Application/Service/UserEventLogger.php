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
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Enum\LoginFailureReason;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Poweradmin\Domain\Service\SessionKeys;

class UserEventLogger
{
    private LegacyLogger $logger;
    private IpAddressRetriever $ipRetriever;

    public function __construct(PDO $db)
    {
        $this->logger = new LegacyLogger($db);
        $this->ipRetriever = new IpAddressRetriever($_SERVER);
    }

    public function logSuccessfulAuth(AuthMethod $authMethod = AuthMethod::SQL): void
    {
        $this->logger->logNotice($this->format('login_success', $authMethod));
    }

    public function logLockout(AuthMethod $authMethod = AuthMethod::SQL): void
    {
        $this->logger->logWarn($this->format('login_locked', $authMethod));
    }

    public function logFailedAuth(AuthMethod $authMethod = AuthMethod::SQL, ?LoginFailureReason $reason = null): void
    {
        $this->logger->logWarn($this->format('login_failed', $authMethod, $reason?->value));
    }

    private function format(string $operation, AuthMethod $authMethod, ?string $reason = null): string
    {
        $base = sprintf(
            'client_ip:%s user:%s operation:%s auth_method:%s',
            $this->ipRetriever->getClientIp(),
            $_SESSION[SessionKeys::USERLOGIN] ?? '',
            $operation,
            $authMethod->value
        );

        return $reason !== null ? $base . ' reason:' . $reason : $base;
    }
}
