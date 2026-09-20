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

use RuntimeException;

/**
 * Stands in for the `exit` that ends a request in production.
 *
 * checkCondition(), checkPermission(), showError() and redirect() all render
 * and exit, so a test double has to abort the same way for the statements after
 * them to stay unreached. The kind and target record which exit it was.
 */
class ControllerHalt extends RuntimeException
{
    public const KIND_CONDITION = 'condition';
    public const KIND_PERMISSION = 'permission';
    public const KIND_ERROR = 'error';
    public const KIND_REDIRECT = 'redirect';

    public function __construct(public readonly string $kind, public readonly string $target)
    {
        parent::__construct($kind . ': ' . $target);
    }
}
