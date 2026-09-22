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

namespace Poweradmin\Tests\Unit\Application\Controller\Record;

use Poweradmin\Application\Controller\Record\EditRecordController;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Service\RecordCommentService;
use ReflectionProperty;

/**
 * Builds the edit-record controller through the ControllerEnvironment seam.
 *
 * The comment service is composed from the repository factory rather than a
 * factory accessor, so the given one is planted into the private property.
 */
class TestableEditRecordController extends EditRecordController
{
    public function __construct(array $request, ControllerEnvironment $environment, RecordCommentService $recordCommentService)
    {
        parent::__construct($request, true, $environment);
        (new ReflectionProperty(EditRecordController::class, 'recordCommentService'))->setValue($this, $recordCommentService);
    }
}
