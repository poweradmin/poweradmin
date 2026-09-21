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

namespace Poweradmin\Tests\Unit\Module;

use Poweradmin\Domain\Module\ModuleInterface;

/**
 * A module that is not in the manifest, for registry tests that supply their own list.
 */
final class StubModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'stub';
    }

    public function getRoutes(): array
    {
        return [];
    }

    public function getNavItems(): array
    {
        return [['label' => 'Stub', 'url' => '/stub']];
    }

    public function getCapabilities(): array
    {
        return [];
    }

    public function getCapabilityData(string $capability): array
    {
        return [];
    }

    public function getTemplatePath(): string
    {
        return __DIR__;
    }
}
