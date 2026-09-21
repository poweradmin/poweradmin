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

namespace Poweradmin\Tests\Unit\Application\Controller;

use Poweradmin\Application\Controller\EditCommentController;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Controller\BaseController;
use ReflectionMethod;

/**
 * Builds the zone comment controller through the ControllerEnvironment seam.
 *
 * redirect() and showError() end the request in production, so each throws a
 * ControllerHalt; the form render is recorded instead of hitting the database
 * for the stored comment.
 */
class TestableEditCommentController extends EditCommentController
{
    /** @var list<array{0: int, 1: bool}> */
    public array $formsShown = [];

    public function __construct(array $request, ControllerEnvironment $environment)
    {
        (new ReflectionMethod(BaseController::class, '__construct'))->invoke($this, $request, true, $environment);
    }

    public function showCommentForm(int $zone_id, bool $perm_edit_comment): void
    {
        $this->formsShown[] = [$zone_id, $perm_edit_comment];
    }

    public function redirect(string $url, array $args = []): void
    {
        throw new ControllerHalt(ControllerHalt::KIND_REDIRECT, $url);
    }

    public function showError(string $error, ?string $recordName = null): void
    {
        throw new ControllerHalt(ControllerHalt::KIND_ERROR, $error);
    }

    protected function refreshPdnsCapabilities(): void
    {
    }
}
