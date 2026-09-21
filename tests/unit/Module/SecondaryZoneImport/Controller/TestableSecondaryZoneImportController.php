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

namespace Poweradmin\Tests\Unit\Module\SecondaryZoneImport\Controller;

use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Module\SecondaryZoneImport\Controller\SecondaryZoneImportController;
use Poweradmin\Tests\Unit\Application\Controller\ControllerHalt;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Builds the controller through the ControllerEnvironment seam. checkPermission(),
 * showError() and redirect() end the request in production, so each throws a
 * ControllerHalt to stop the run at the same statement; render() only records.
 */
class TestableSecondaryZoneImportController extends SecondaryZoneImportController
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $rendered = [];

    public function __construct(array $request, ControllerEnvironment $environment)
    {
        (new ReflectionMethod(BaseController::class, '__construct'))->invoke($this, $request, true, $environment);
        (new ReflectionProperty(SecondaryZoneImportController::class, 'userContextService'))->setValue($this, new UserContextService());
    }

    public function render(string $template, array $params): void
    {
        $this->rendered[] = [$template, $params];
    }

    public function checkPermission(string $permission, string $errorMessage): void
    {
        if (!$this->hasPermission($permission)) {
            throw new ControllerHalt(ControllerHalt::KIND_PERMISSION, $errorMessage);
        }
    }

    public function redirect(string $url, array $args = []): void
    {
        throw new ControllerHalt(ControllerHalt::KIND_REDIRECT, $args === [] ? $url : $url . '?' . http_build_query($args));
    }

    public function showError(string $error, ?string $recordName = null): void
    {
        throw new ControllerHalt(ControllerHalt::KIND_ERROR, $error);
    }

    protected function refreshPdnsCapabilities(): void
    {
    }
}
