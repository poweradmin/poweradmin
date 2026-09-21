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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Model\PdnsCapabilities;

class DnssecAlgorithmNameBitsTest extends TestCase
{
    public function testEverySupportedAlgorithmHasAKeySizeList(): void
    {
        $bits = DnssecAlgorithmName::getAlgorithmBitsForCapabilities(null);

        $this->assertSame(DnssecAlgorithmName::SUPPORTED_ALGORITHMS, array_keys($bits));
        $this->assertSame([256], $bits[DnssecAlgorithmName::ECDSA256]);
        $this->assertSame([1024, 2048], $bits[DnssecAlgorithmName::RSASHA256]);
    }

    public function testMapFollowsTheServerAlgorithmFilter(): void
    {
        $bits = DnssecAlgorithmName::getAlgorithmBitsForCapabilities(PdnsCapabilities::fromVersion('4.4.0'));

        $this->assertArrayNotHasKey(DnssecAlgorithmName::ED448, $bits);
        $this->assertArrayHasKey(DnssecAlgorithmName::ED25519, $bits);
    }
}
