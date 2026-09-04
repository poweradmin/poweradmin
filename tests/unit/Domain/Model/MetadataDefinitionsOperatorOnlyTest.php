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
use Poweradmin\Domain\Model\MetadataDefinitions;

class MetadataDefinitionsOperatorOnlyTest extends TestCase
{
    /**
     * Enabling Lua evaluation reaches every zone the PowerDNS process serves,
     * so these kinds must never be settable by a zone-level editor.
     */
    public function testLuaEnablingKindsAreOperatorOnly(): void
    {
        $this->assertTrue(MetadataDefinitions::isOperatorOnly('ENABLE-LUA-RECORDS'));
        $this->assertTrue(MetadataDefinitions::isOperatorOnly('LUA-AXFR-SCRIPT'));
    }

    public function testOrdinaryKindsAreNotOperatorOnly(): void
    {
        $this->assertFalse(MetadataDefinitions::isOperatorOnly('SOA-EDIT'));
        $this->assertFalse(MetadataDefinitions::isOperatorOnly('ALLOW-AXFR-FROM'));
    }

    public function testUnknownCustomKindIsNotOperatorOnly(): void
    {
        $this->assertFalse(MetadataDefinitions::isOperatorOnly('X-CUSTOM-KIND'));
    }

    /**
     * The editor gates on isOperatorOnly(), so a kind that is operator-only must
     * stay non-api-writable too; otherwise the v2 metadata endpoint would remain
     * an open path to the same write.
     */
    public function testOperatorOnlyKindsAreAlsoBlockedOnTheApiEndpoint(): void
    {
        foreach (array_keys(MetadataDefinitions::DEFINITIONS) as $kind) {
            if (MetadataDefinitions::isOperatorOnly($kind)) {
                $this->assertFalse(
                    MetadataDefinitions::isApiWritable($kind),
                    sprintf('Operator-only kind %s must not be writable through the API endpoint', $kind)
                );
            }
        }
    }
}
