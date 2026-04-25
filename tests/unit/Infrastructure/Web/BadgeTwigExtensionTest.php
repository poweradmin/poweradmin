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

namespace Poweradmin\Tests\Unit\Infrastructure\Web;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Web\BadgeTwigExtension;

class BadgeTwigExtensionTest extends TestCase
{
    private BadgeTwigExtension $ext;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->ext = new BadgeTwigExtension();
    }

    public function testZoneTypeLabelUsesPrimarySecondaryOn45(): void
    {
        $this->setSessionVersion('4.5.0');
        $this->assertSame('Primary', $this->ext->getZoneTypeLabel('MASTER'));
        $this->assertSame('Secondary', $this->ext->getZoneTypeLabel('SLAVE'));
        $this->assertSame('Native', $this->ext->getZoneTypeLabel('NATIVE'));
    }

    public function testZoneTypeLabelKeepsLegacyTerminologyBefore45(): void
    {
        $this->setSessionVersion('4.4.3');
        $this->assertSame('Master', $this->ext->getZoneTypeLabel('MASTER'));
        $this->assertSame('Slave', $this->ext->getZoneTypeLabel('SLAVE'));
        $this->assertSame('Native', $this->ext->getZoneTypeLabel('NATIVE'));
    }

    public function testZoneTypeLabelOnUnknownVersionFallsBackToLegacyTerminology(): void
    {
        // Strict mode: unknown version means we don't know whether the
        // server prefers modern aliases, so legacy labels stay.
        $this->assertSame('Master', $this->ext->getZoneTypeLabel('MASTER'));
        $this->assertSame('Slave', $this->ext->getZoneTypeLabel('SLAVE'));
    }

    public function testZoneTypeLabelHandlesCaseAndProducerConsumer(): void
    {
        $this->setSessionVersion('4.7.0');
        $this->assertSame('Primary', $this->ext->getZoneTypeLabel('master'));
        $this->assertSame('Producer', $this->ext->getZoneTypeLabel('PRODUCER'));
        $this->assertSame('Consumer', $this->ext->getZoneTypeLabel('CONSUMER'));
    }

    public function testZoneTypeLabelEmptyAndNullInputReturnEmpty(): void
    {
        $this->assertSame('', $this->ext->getZoneTypeLabel(null));
        $this->assertSame('', $this->ext->getZoneTypeLabel(''));
    }

    public function testZoneTypeLabelUnknownKindFallsBackToTitleCase(): void
    {
        $this->setSessionVersion('4.5.0');
        $this->assertSame('Foobar', $this->ext->getZoneTypeLabel('FOOBAR'));
    }

    private function setSessionVersion(string $version): void
    {
        $_SESSION['pdns_server_info'] = [
            'info' => [
                'version' => $version,
                'daemon_type' => 'authoritative',
                'id' => 'localhost',
            ],
            'fetched_at' => time(),
        ];
    }
}
