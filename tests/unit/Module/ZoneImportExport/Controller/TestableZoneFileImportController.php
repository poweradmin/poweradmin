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

namespace Poweradmin\Tests\Unit\Module\ZoneImportExport\Controller;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Module\ZoneImportExport\Controller\ZoneFileImportController;
use Poweradmin\Tests\Unit\Application\Controller\ControllerHalt;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Builds the zone file import controller through the ControllerEnvironment
 * seam, wiring its private collaborators as its own constructor does. showError()
 * and checkCondition() end the request in production, so each throws a
 * ControllerHalt at the same statement.
 */
class TestableZoneFileImportController extends ZoneFileImportController
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $rendered = [];

    public function __construct(array $request, ControllerEnvironment $environment)
    {
        (new ReflectionMethod(BaseController::class, '__construct'))->invoke($this, $request, true, $environment);

        (new ReflectionProperty(ZoneFileImportController::class, 'userContextService'))->setValue($this, new UserContextService());
    }

    public function render(string $template, array $params): void
    {
        $this->rendered[] = [$template, $params];
    }

    /** @return array<string, mixed> */
    public function renderedParams(): array
    {
        return $this->rendered[0][1] ?? [];
    }

    public function checkCondition(bool $condition, string $errorMessage): void
    {
        if ($condition) {
            throw new ControllerHalt(ControllerHalt::KIND_CONDITION, $errorMessage);
        }
    }

    public function showError(string $error, ?string $recordName = null): void
    {
        throw new ControllerHalt(ControllerHalt::KIND_ERROR, $error);
    }

    protected function refreshPdnsCapabilities(): void
    {
    }
}
