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
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

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
        $this->logger->logNotice(sprintf(
            'client_ip:%s user:%s operation:login_success auth_method:%s',
            $this->ipRetriever->getClientIp(),
            $_SESSION['userlogin'],
            $authMethod->value
        ));
    }

    public function logFailedAuth(AuthMethod $authMethod = AuthMethod::SQL): void
    {
        $this->logger->logWarn(sprintf(
            'client_ip:%s user:%s operation:login_failed auth_method:%s',
            $this->ipRetriever->getClientIp(),
            $_SESSION["userlogin"],
            $authMethod->value
        ));
    }
}
