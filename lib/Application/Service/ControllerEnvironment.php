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
use Poweradmin\Application\Http\Request as HttpRequest;
use Poweradmin\Application\Module\ModuleRegistry;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Service\MessageService;
use Psr\Log\LoggerInterface;

/**
 * Pre-built collaborators for a controller, bypassing the eager bootstrap
 * (config file, database connection, session authentication) that
 * BaseController::__construct() otherwise performs.
 *
 * This is a test seam: the router never passes one, so production behaviour is
 * untouched. Tests construct a controller directly with stubbed services, most
 * usefully a stub ControllerServiceFactory, which the create*() accessors on
 * BaseController all route through.
 *
 * The request carries the method and the query and post parameters the
 * controller reads (isPost(), the request helpers); without one the controller
 * snapshots the superglobals as it does in production.
 */
final class ControllerEnvironment
{
    public function __construct(
        public readonly ConfigurationInterface $config,
        public readonly PDO $db,
        public readonly LoggerInterface $logger,
        public readonly ModuleRegistry $moduleRegistry,
        public readonly ?ControllerServiceFactory $serviceFactory = null,
        public readonly ?HttpRequest $httpRequest = null,
        public readonly ?CsrfTokenService $csrfTokenService = null,
        public readonly ?MessageService $messageService = null,
        public readonly ?UserContextService $userContextService = null
    ) {
    }
}
