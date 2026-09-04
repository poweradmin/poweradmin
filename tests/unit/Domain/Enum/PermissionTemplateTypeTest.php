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


namespace Poweradmin\Tests\Unit\Domain\Enum;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Enum\PermissionTemplateType;

class PermissionTemplateTypeTest extends TestCase
{
    /**
     * The OpenAPI schema declares this vocabulary as an attribute literal, which
     * cannot call a method - so it has to stay in step with these values by hand.
     */
    public function testValuesMatchThePublishedApiVocabulary(): void
    {
        $this->assertSame(['user', 'group'], PermissionTemplateType::values());
    }

    public function testValidation(): void
    {
        $this->assertTrue(PermissionTemplateType::isValid('user'));
        $this->assertTrue(PermissionTemplateType::isValid('group'));
        $this->assertFalse(PermissionTemplateType::isValid('admin'));
    }
}
