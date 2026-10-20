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

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\LdapBindPassword;

class LdapBindPasswordTest extends TestCase
{
    public static function refused(): array
    {
        return [
            'empty' => [''],
            'leading NUL' => ["\0secret"],
            'embedded NUL' => ["sec\0ret"],
            'only NUL' => ["\0"],
        ];
    }

    #[DataProvider('refused')]
    public function testPasswordsThatCannotBeBoundAreRefused(string $password): void
    {
        $this->assertFalse(LdapBindPassword::isUsable($password));
    }

    public static function accepted(): array
    {
        return [
            'zero digit' => ['0'],
            'single space' => [' '],
            'ordinary' => ['correct-horse'],
            'multibyte' => ['slaptažodis'],
        ];
    }

    #[DataProvider('accepted')]
    public function testRealPasswordsAreAccepted(string $password): void
    {
        $this->assertTrue(LdapBindPassword::isUsable($password));
    }
}
