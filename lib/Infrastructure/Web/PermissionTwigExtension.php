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

namespace Poweradmin\Infrastructure\Web;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PermissionTwigExtension extends AbstractExtension
{
    /** @var callable(string): bool */
    private $permissionChecker;

    /**
     * @param callable(string): bool $permissionChecker Deferred check for the
     *        current user; only invoked when a template calls can().
     */
    public function __construct(callable $permissionChecker)
    {
        $this->permissionChecker = $permissionChecker;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can', [$this, 'can']),
        ];
    }

    /** Whether the current user holds the given permission; false when unauthenticated. */
    public function can(string $permission): bool
    {
        return (bool)($this->permissionChecker)($permission);
    }
}
