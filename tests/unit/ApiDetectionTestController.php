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
 */

namespace Poweradmin\Tests\Unit;

use Poweradmin\BaseController;

/**
 * Concrete BaseController used only by BaseControllerApiDetectionTest. It is never
 * constructed (newInstanceWithoutConstructor), so run() only satisfies the abstract
 * contract.
 */
class ApiDetectionTestController extends BaseController
{
    public function run(): void
    {
    }
}
