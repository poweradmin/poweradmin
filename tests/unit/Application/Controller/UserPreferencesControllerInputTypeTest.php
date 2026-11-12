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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\Internal\UserPreferencesController;
use ReflectionClass;
use ReflectionMethod;

/**
 * A non-string key or value reaches the typed preference service and ends in a TypeError
 * instead of a 400 response.
 */
class UserPreferencesControllerInputTypeTest extends TestCase
{
    #[DataProvider('inputs')]
    public function testAcceptsOnlyStringKeyAndValue(array $input, bool $expected): void
    {
        $controller = (new ReflectionClass(UserPreferencesController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($controller, 'hasStringKeyAndValue');

        $this->assertSame($expected, $method->invoke($controller, $input));
    }

    public static function inputs(): array
    {
        return [
            'strings' => [['key' => 'timezone', 'value' => 'UTC'], true],
            'array key' => [['key' => ['x'], 'value' => 'UTC'], false],
            'array value' => [['key' => 'timezone', 'value' => ['UTC']], false],
            'int value' => [['key' => 'timezone', 'value' => 5], false],
            'bool value' => [['key' => 'timezone', 'value' => true], false],
            'int key' => [['key' => 1, 'value' => 'UTC'], false],
        ];
    }
}
