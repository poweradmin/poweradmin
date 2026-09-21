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

use Poweradmin\Application\Controller\DatabaseConsistencyController;

/**
 * Builds the database consistency controller through the ControllerEnvironment
 * seam. showError() ends the request in production, so it throws a ControllerHalt;
 * redirect() only records its target, so a redirect issued inside a try block is
 * not mistaken for a failure.
 */
class TestableDatabaseConsistencyController extends DatabaseConsistencyController
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $rendered = [];

    /** @var list<string> */
    public array $redirects = [];

    public function render(string $template, array $params): void
    {
        $this->rendered[] = [$template, $params];
    }

    public function redirect(string $url, array $args = []): void
    {
        $this->redirects[] = $url;
    }

    public function showError(string $error, ?string $recordName = null): void
    {
        throw new ControllerHalt(ControllerHalt::KIND_ERROR, $error);
    }
}
