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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\Internal;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\Internal\UserPreferencesController;
use ReflectionMethod;

class UserPreferencesControllerInputTest extends TestCase
{
    private function valid(?array $input): bool
    {
        return (new ReflectionMethod(UserPreferencesController::class, 'isValidUpdateInput'))->invoke(null, $input);
    }

    public function testAcceptsStringKeyAndValue(): void
    {
        $this->assertTrue($this->valid(['key' => 'theme', 'value' => 'dark']));
    }

    public function testRejectsNonStringKeyOrValue(): void
    {
        $this->assertFalse($this->valid(['key' => ['a'], 'value' => 'dark']));
        $this->assertFalse($this->valid(['key' => 'theme', 'value' => ['x']]));
        $this->assertFalse($this->valid(['key' => 'theme', 'value' => 5]));
        $this->assertFalse($this->valid(['key' => 'theme']));
        $this->assertFalse($this->valid(null));
    }
}
