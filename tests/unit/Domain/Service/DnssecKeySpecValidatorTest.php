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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnssecKeySpecValidator;

class DnssecKeySpecValidatorTest extends TestCase
{
    public static function validCombinations(): array
    {
        return [
            'ecdsa256 with 256' => ['ecdsa256', '256'],
            'ecdsa384 with 384' => ['ecdsa384', '384'],
            'ed25519 with 256' => ['ed25519', '256'],
            'rsasha256 with 2048' => ['rsasha256', '2048'],
            'rsasha512 with 1024' => ['rsasha512', '1024'],
        ];
    }

    public static function invalidCombinations(): array
    {
        return [
            'ecdsa256 with 384' => ['ecdsa256', '384'],
            'ecdsa384 with 256' => ['ecdsa384', '256'],
            'ed25519 with 2048' => ['ed25519', '2048'],
            'ed448 with 256' => ['ed448', '256'],
            'rsasha256 with 768' => ['rsasha256', '768'],
            'rsasha1 with 256' => ['rsasha1', '256'],
        ];
    }

    #[DataProvider('validCombinations')]
    public function testAcceptsValidCombination(string $algorithm, string $bits): void
    {
        $this->assertNull(DnssecKeySpecValidator::validateAlgorithmBits($algorithm, $bits));
    }

    #[DataProvider('invalidCombinations')]
    public function testRejectsInvalidCombination(string $algorithm, string $bits): void
    {
        $this->assertIsString(DnssecKeySpecValidator::validateAlgorithmBits($algorithm, $bits));
    }

    public function testValidBits(): void
    {
        $this->assertTrue(DnssecKeySpecValidator::isValidBits('2048'));
        $this->assertTrue(DnssecKeySpecValidator::isValidBits('256'));
        $this->assertFalse(DnssecKeySpecValidator::isValidBits('4096'));
        $this->assertFalse(DnssecKeySpecValidator::isValidBits(''));
    }
}
