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

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Controller\Auth;

use Poweradmin\Application\Controller\Auth\MfaVerifyController;
use Poweradmin\Application\Service\LoginAttemptService;
use ReflectionProperty;

/**
 * Builds the MFA verification controller through the ControllerEnvironment
 * seam and captures the page it would have rendered. The attempt counter is
 * planted, since the real one reads and writes the login_attempts table.
 */
class TestableMfaVerifyController extends MfaVerifyController
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $rendered = [];

    public function plantLoginAttemptService(LoginAttemptService $service): void
    {
        (new ReflectionProperty(MfaVerifyController::class, 'loginAttemptService'))->setValue($this, $service);
    }

    public function render(string $template, array $params): void
    {
        $this->rendered[] = [$template, $params];
    }
}
