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
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Infrastructure\Web\BadgeTwigExtension;

class BadgeTwigExtensionTest extends TestCase
{
    private BadgeTwigExtension $ext;
    /** @var array<string, mixed> */
    private array $context = [];

    protected function setUp(): void
    {
        $this->context = [];
        $this->ext = new BadgeTwigExtension();
    }

    public function testZoneTypeLabelUsesPrimarySecondaryOn45(): void
    {
        $this->setSessionVersion('4.5.0');
        $this->assertSame('Primary', $this->ext->getZoneTypeLabel($this->context, 'MASTER'));
        $this->assertSame('Secondary', $this->ext->getZoneTypeLabel($this->context, 'SLAVE'));
        $this->assertSame('Native', $this->ext->getZoneTypeLabel($this->context, 'NATIVE'));
    }

    public function testZoneTypeLabelKeepsLegacyTerminologyBefore45(): void
    {
        $this->setSessionVersion('4.4.3');
        $this->assertSame('Master', $this->ext->getZoneTypeLabel($this->context, 'MASTER'));
        $this->assertSame('Slave', $this->ext->getZoneTypeLabel($this->context, 'SLAVE'));
        $this->assertSame('Native', $this->ext->getZoneTypeLabel($this->context, 'NATIVE'));
    }

    public function testZoneTypeLabelOnUnknownVersionFallsBackToLegacyTerminology(): void
    {
        // Strict mode: unknown version means we don't know whether the
        // server prefers modern aliases, so legacy labels stay.
        $this->assertSame('Master', $this->ext->getZoneTypeLabel($this->context, 'MASTER'));
        $this->assertSame('Slave', $this->ext->getZoneTypeLabel($this->context, 'SLAVE'));
    }

    public function testZoneTypeLabelHandlesCaseAndProducerConsumer(): void
    {
        $this->setSessionVersion('4.7.0');
        $this->assertSame('Primary', $this->ext->getZoneTypeLabel($this->context, 'master'));
        $this->assertSame('Producer', $this->ext->getZoneTypeLabel($this->context, 'PRODUCER'));
        $this->assertSame('Consumer', $this->ext->getZoneTypeLabel($this->context, 'CONSUMER'));
    }

    public function testZoneTypeLabelEmptyAndNullInputReturnEmpty(): void
    {
        $this->assertSame('', $this->ext->getZoneTypeLabel($this->context, null));
        $this->assertSame('', $this->ext->getZoneTypeLabel($this->context, ''));
    }

    public function testIsZoneReadOnlyForReplicatedKinds(): void
    {
        $this->assertTrue($this->ext->isZoneReadOnly('SLAVE'));
        $this->assertTrue($this->ext->isZoneReadOnly('consumer'));
        $this->assertFalse($this->ext->isZoneReadOnly('MASTER'));
        $this->assertFalse($this->ext->isZoneReadOnly('PRODUCER'));
        $this->assertFalse($this->ext->isZoneReadOnly(null));
    }

    public function testZoneTypeLabelUnknownKindFallsBackToTitleCase(): void
    {
        $this->setSessionVersion('4.5.0');
        $this->assertSame('Foobar', $this->ext->getZoneTypeLabel($this->context, 'FOOBAR'));
    }

    public function testAutoprimariesLabelUsesModernTermFrom46(): void
    {
        $this->setSessionVersion('4.6.0');
        $this->assertSame('Autoprimaries', $this->ext->getAutoprimariesLabel($this->context, 'plural'));
        $this->assertSame('Autoprimary', $this->ext->getAutoprimariesLabel($this->context, 'singular'));
        $this->assertSame('Add autoprimary', $this->ext->getAutoprimariesLabel($this->context, 'add_action'));
        $this->assertSame('Edit autoprimary', $this->ext->getAutoprimariesLabel($this->context, 'edit_action'));
        $this->assertSame('Delete autoprimary', $this->ext->getAutoprimariesLabel($this->context, 'delete_action'));
        $this->assertSame('About Autoprimaries', $this->ext->getAutoprimariesLabel($this->context, 'about_title'));
        $this->assertSame('IP address of autoprimary', $this->ext->getAutoprimariesLabel($this->context, 'ip_label'));
    }

    public function testAutoprimariesLabelKeepsLegacyTermBefore46(): void
    {
        $this->setSessionVersion('4.5.9');
        $this->assertSame('Supermasters', $this->ext->getAutoprimariesLabel($this->context, 'plural'));
        $this->assertSame('Supermaster', $this->ext->getAutoprimariesLabel($this->context, 'singular'));
        $this->assertSame('Add supermaster', $this->ext->getAutoprimariesLabel($this->context, 'add_action'));
    }

    public function testAutoprimariesLabelUnknownVersionStaysOnLegacyTerm(): void
    {
        // Strict mode: unknown server version means "we don't know it's 4.6+",
        // so keep the long-standing Supermaster label.
        $this->assertSame('Supermasters', $this->ext->getAutoprimariesLabel($this->context, 'plural'));
        $this->assertSame('Supermaster', $this->ext->getAutoprimariesLabel($this->context, 'singular'));
    }

    public function testAutoprimariesLabelUnknownKeyFallsBackToPlural(): void
    {
        $this->setSessionVersion('4.7.0');
        $this->assertSame('Autoprimaries', $this->ext->getAutoprimariesLabel($this->context, 'something_unrecognized'));
    }

    private function setSessionVersion(string $version): void
    {
        $this->context = ['pdns_caps' => PdnsCapabilities::fromVersion($version)];
    }
}
