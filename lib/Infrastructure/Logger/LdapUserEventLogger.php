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

namespace Poweradmin\Infrastructure\Logger;

use PDO;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Enum\LoginFailureReason;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

class LdapUserEventLogger
{
    private LegacyLogger $logger;
    private IpAddressRetriever $ipRetriever;

    public function __construct(PDO $db)
    {
        $this->logger = new LegacyLogger($db);
        $this->ipRetriever = new IpAddressRetriever($_SERVER);
    }

    public function logFailedReason($reason): void
    {
        // Backend infrastructure failure (e.g. LDAP unreachable, service-bind failed,
        // search error) — emit operation:login_error so fail2ban filters anchored on
        // operation:login_failed do not punish clients for server-side outages.
        $normalized = $reason === 'ldap_search' ? LoginFailureReason::LDAP_SEARCH_FAILED->value : (string) $reason;
        $this->logger->logError(sprintf(
            'client_ip:%s user:%s operation:login_error auth_method:%s reason:%s',
            $this->ipRetriever->getClientIp(),
            $_SESSION["userlogin"] ?? '',
            AuthMethod::LDAP->value,
            $normalized
        ));
    }

    public function logLockout(): void
    {
        $this->logger->logWarn(sprintf(
            'client_ip:%s user:%s operation:login_locked auth_method:%s',
            $this->ipRetriever->getClientIp(),
            $_SESSION["userlogin"] ?? '',
            AuthMethod::LDAP->value
        ));
    }

    public function logSuccessAuth(): void
    {
        $this->logger->logNotice(sprintf(
            'client_ip:%s user:%s operation:login_success auth_method:%s',
            $this->ipRetriever->getClientIp(),
            $_SESSION["userlogin"] ?? '',
            AuthMethod::LDAP->value
        ));
    }

    public function logFailedAuth(): void
    {
        $this->logger->logWarn($this->formatFailure(LoginFailureReason::NO_SUCH_USER));
    }

    public function logFailedDuplicateAuth(): void
    {
        $this->logger->logError($this->formatFailure(LoginFailureReason::DUPLICATE_USERS));
    }

    public function logFailedIncorrectPass(): void
    {
        $this->logger->logWarn($this->formatFailure(LoginFailureReason::WRONG_PASSWORD));
    }

    public function logFailedUserInactive(): void
    {
        $this->logger->logWarn($this->formatFailure(LoginFailureReason::ACCOUNT_DISABLED));
    }

    private function formatFailure(LoginFailureReason $reason): string
    {
        return sprintf(
            'client_ip:%s user:%s operation:login_failed auth_method:%s reason:%s',
            $this->ipRetriever->getClientIp(),
            $_SESSION["userlogin"] ?? '',
            AuthMethod::LDAP->value,
            $reason->value
        );
    }
}
