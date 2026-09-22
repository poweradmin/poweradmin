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

namespace Poweradmin\Application\Controller;

use Error;

/**
 * Ends a controller run whose response is already written: the error page,
 * the redirect header or the JSON body went out, and nothing may follow it.
 *
 * SymfonyRouter::process() catches it and returns, which is what the `exit`
 * it replaces used to do. It is an Error rather than an Exception on purpose:
 * controllers wrap backend calls in catch (Exception) blocks, and a halt
 * raised inside one of those must not be mistaken for a failed call.
 *
 * The kind and target say which site halted, so a test can assert on them.
 */
final class RequestHalted extends Error
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
