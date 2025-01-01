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

namespace Poweradmin\Infrastructure\Logger;

class LdapUserEventLogger
{
    private LegacyLogger $logger;

    public function __construct($db)
    {
        $this->logger = new LegacyLogger($db);
    }

    public function log_failed_reason($reason): void
    {
        $this->logger->log_error(sprintf('Failed LDAP authentication attempt from [%s] Reason: %s failed', $_SERVER['REMOTE_ADDR'], $reason));
    }

    public function log_success_auth(): void
    {
        $this->logger->log_notice(sprintf('Successful LDAP authentication attempt from [%s] for user \'%s\'', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
    }

    public function log_failed_auth(): void
    {
        $this->logger->log_warn(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: No such user', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
    }

    public function log_failed_duplicate_auth(): void
    {
        $this->logger->log_error(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: Duplicate usernames detected', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
    }

    public function log_failed_incorrect_pass(): void
    {
        $this->logger->log_warn(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: Incorrect password', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
    }

    public function log_failed_user_inactive(): void
    {
        $this->logger->log_warn(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: User is inactive', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
    }
}