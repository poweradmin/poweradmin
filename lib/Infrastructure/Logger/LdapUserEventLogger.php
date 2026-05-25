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
use Poweradmin\Domain\Service\SessionKeys;

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
        // operation:login_error (not login_failed) - backend/infra failures must
        // not feed fail2ban brute-force counters during an LDAP outage.
        $normalized = $reason === 'ldap_search' ? LoginFailureReason::LDAP_SEARCH_FAILED->value : (string) $reason;
        $this->logger->logError($this->format('login_error', $normalized));
    }

    public function logLockout(): void
    {
        $this->logger->logWarn($this->format('login_locked'));
    }

    public function logSuccessAuth(): void
    {
        $this->logger->logNotice($this->format('login_success'));
    }

    public function logFailedAuth(): void
    {
        $this->logger->logWarn($this->format('login_failed', LoginFailureReason::NO_SUCH_USER->value));
    }

    public function logFailedDuplicateAuth(): void
    {
        $this->logger->logError($this->format('login_failed', LoginFailureReason::DUPLICATE_USERS->value));
    }

    public function logFailedIncorrectPass(): void
    {
        $this->logger->logWarn($this->format('login_failed', LoginFailureReason::WRONG_PASSWORD->value));
    }

    public function logFailedUserInactive(): void
    {
        $this->logger->logWarn($this->format('login_failed', LoginFailureReason::ACCOUNT_DISABLED->value));
    }

    private function format(string $operation, ?string $reason = null): string
    {
        $base = sprintf(
            'client_ip:%s user:%s operation:%s auth_method:%s',
            $this->ipRetriever->getClientIp(),
            $_SESSION[SessionKeys::USERLOGIN] ?? '',
            $operation,
            AuthMethod::LDAP->value
        );

        return $reason !== null ? $base . ' reason:' . $reason : $base;
    }
}
