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

namespace Poweradmin\Tests\Unit\Application\Controller\Zone;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Controller\Zone\ZoneOwnershipController;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Domain\Service\Auth\UserContextService;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Builds the controller through the ControllerEnvironment seam and fills the
 * private collaborators from the stub factory, as the real constructor does.
 */
class TestableZoneOwnershipController extends ZoneOwnershipController
{
    public function __construct(array $request, ControllerEnvironment $environment)
    {
        (new ReflectionMethod(BaseController::class, '__construct'))->invoke($this, $request, true, $environment);

        $services = $this->services();
        $this->set('userContextService', new UserContextService());
        $this->set('zoneRepository', $services->zoneRepository());
        $this->set('domainRepository', $services->domainRepository());
        $this->set('permissionService', $services->permissionService());
    }

    private function set(string $property, object $value): void
    {
        (new ReflectionProperty(ZoneOwnershipController::class, $property))->setValue($this, $value);
    }
}
