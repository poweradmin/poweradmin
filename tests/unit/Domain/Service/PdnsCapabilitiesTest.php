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
use Poweradmin\Domain\Service\PdnsCapabilities;

class PdnsCapabilitiesTest extends TestCase
{
    public function testFromVersionStripsNonNumericPrefix(): void
    {
        $caps = PdnsCapabilities::fromVersion('git-4.9.2');
        $this->assertSame('4.9.2', $caps->version());
        $this->assertTrue($caps->isKnown());
    }

    public function testEmptyAndNullVersionAreUnknown(): void
    {
        $this->assertFalse(PdnsCapabilities::fromVersion(null)->isKnown());
        $this->assertFalse(PdnsCapabilities::fromVersion('')->isKnown());
    }

    public function testIsAtLeastUsesUnknownDefaultWhenVersionMissing(): void
    {
        $unknown = PdnsCapabilities::fromVersion(null);
        $this->assertFalse($unknown->isAtLeast('4.5.0'));
        $this->assertTrue($unknown->isAtLeast('4.5.0', true));
    }

    public function testIsAtLeastComparesVersionsNumerically(): void
    {
        $caps = PdnsCapabilities::fromVersion('4.7.3');
        $this->assertTrue($caps->isAtLeast('4.7.0'));
        $this->assertTrue($caps->isAtLeast('4.7.3'));
        $this->assertFalse($caps->isAtLeast('4.8.0'));
    }

    public function testTerminologyPrefersPrimarySecondaryFrom45(): void
    {
        $this->assertFalse(PdnsCapabilities::fromVersion('4.4.3')->prefersPrimarySecondaryTerminology());
        $this->assertTrue(PdnsCapabilities::fromVersion('4.5.0')->prefersPrimarySecondaryTerminology());
        $this->assertTrue(PdnsCapabilities::fromVersion('5.0.0')->prefersPrimarySecondaryTerminology());
        // Strict default for unknown - keep legacy terminology.
        $this->assertFalse(PdnsCapabilities::fromVersion(null)->prefersPrimarySecondaryTerminology());
    }

    public function testZoneKindGates(): void
    {
        $this->assertFalse(PdnsCapabilities::fromVersion('4.6.0')->supportsCatalogZones());
        $this->assertTrue(PdnsCapabilities::fromVersion('4.7.0')->supportsCatalogZones());

        $this->assertFalse(PdnsCapabilities::fromVersion('4.9.0')->supportsViews());
        $this->assertTrue(PdnsCapabilities::fromVersion('5.0.0')->supportsViews());
    }

    #[DataProvider('recordTypeProvider')]
    public function testSupportsRecordType(string $version, string $type, bool $expected): void
    {
        $this->assertSame(
            $expected,
            PdnsCapabilities::fromVersion($version)->supportsRecordType($type)
        );
    }

    public static function recordTypeProvider(): array
    {
        return [
            'A is always supported' => ['4.0.0', 'A', true],
            'CNAME is always supported' => ['4.0.0', 'cname', true],
            'SVCB available from 4.4' => ['4.4.0', 'SVCB', true],
            'SVCB unavailable on 4.3' => ['4.3.0', 'SVCB', false],
            'HTTPS unavailable on 4.3' => ['4.3.0', 'HTTPS', false],
            'CSYNC available from 4.5' => ['4.5.0', 'CSYNC', true],
            'CSYNC unavailable on 4.4' => ['4.4.0', 'CSYNC', false],
            'ZONEMD available from 4.8' => ['4.8.1', 'ZONEMD', true],
            'ZONEMD unavailable on 4.7' => ['4.7.0', 'ZONEMD', false],
            'WALLET available from 5.1' => ['5.1.0', 'WALLET', true],
            'WALLET unavailable on 5.0' => ['5.0.0', 'WALLET', false],
            'lowercase input is normalised' => ['4.4.0', 'svcb', true],
        ];
    }

    public function testApiEndpointGates(): void
    {
        $v45 = PdnsCapabilities::fromVersion('4.5.9');
        $v46 = PdnsCapabilities::fromVersion('4.6.0');
        $this->assertFalse($v45->supportsIndividualRrsetFetch());
        $this->assertTrue($v46->supportsIndividualRrsetFetch());
        $this->assertFalse($v45->supportsAutoprimariesApi());
        $this->assertTrue($v46->supportsAutoprimariesApi());

        $this->assertFalse(PdnsCapabilities::fromVersion('4.8.0')->supportsRecordTimestamps());
        $this->assertTrue(PdnsCapabilities::fromVersion('4.9.0')->supportsRecordTimestamps());
    }

    public function testDnssecGates(): void
    {
        $this->assertFalse(PdnsCapabilities::fromVersion('3.4.11')->supportsDefaultCsk());
        $this->assertTrue(PdnsCapabilities::fromVersion('4.0.0')->supportsDefaultCsk());
        // Unknown version is conservative for default-behaviour questions.
        $this->assertFalse(PdnsCapabilities::fromVersion(null)->supportsDefaultCsk());

        $this->assertFalse(PdnsCapabilities::fromVersion('4.6.0')->supportsPemKeyImportExport());
        $this->assertTrue(PdnsCapabilities::fromVersion('4.7.0')->supportsPemKeyImportExport());

        $this->assertFalse(PdnsCapabilities::fromVersion('4.9.0')->supportsRfc9615Bootstrap());
        $this->assertTrue(PdnsCapabilities::fromVersion('5.0.0')->supportsRfc9615Bootstrap());
    }

    public function testSupportsMetadataKindRespectsMinVersion(): void
    {
        $caps = PdnsCapabilities::fromVersion('4.5.0');
        $this->assertTrue($caps->supportsMetadataKind('4.0.0'));
        $this->assertTrue($caps->supportsMetadataKind('4.5.0'));
        $this->assertFalse($caps->supportsMetadataKind('4.7.0'));

        // No min_version specified means always supported.
        $this->assertTrue($caps->supportsMetadataKind(null));
        $this->assertTrue($caps->supportsMetadataKind(''));

        // Unknown server version is strict - hide kinds whose min_version
        // can't be confirmed. Kinds with no min_version remain visible.
        $unknown = PdnsCapabilities::fromVersion(null);
        $this->assertFalse($unknown->supportsMetadataKind('5.1.0'));
        $this->assertTrue($unknown->supportsMetadataKind(null));
    }

    public function testPermissiveInstanceReturnsTrueForAllVisibilityMethods(): void
    {
        // SQL/DB-backed installs have no version-detection path, so
        // BaseController hands the UI a permissive instance to avoid
        // hiding modern features the server actually supports.
        $caps = PdnsCapabilities::permissive();

        $this->assertTrue($caps->isKnown());
        $this->assertTrue($caps->prefersPrimarySecondaryTerminology());
        $this->assertTrue($caps->supportsCatalogZones());
        $this->assertTrue($caps->supportsViews());
        $this->assertTrue($caps->supportsRecordType('SVCB'));
        $this->assertTrue($caps->supportsRecordType('ZONEMD'));
        $this->assertTrue($caps->supportsRecordType('WALLET'));
        $this->assertTrue($caps->supportsIndividualRrsetFetch());
        $this->assertTrue($caps->supportsAutoprimariesApi());
        $this->assertTrue($caps->supportsRecordTimestamps());
        $this->assertTrue($caps->supportsDefaultCsk());
        $this->assertTrue($caps->supportsPemKeyImportExport());
        $this->assertTrue($caps->supportsRfc9615Bootstrap());
        $this->assertTrue($caps->supportsMetadataKind('5.1.0'));

        // isAtLeast on a permissive instance is always true.
        $this->assertTrue($caps->isAtLeast('99.99.99'));
    }

    /**
     * Exhaustive check that every feature-visibility method returns false
     * when the connected version is unknown. The point of strict mode is
     * that unreachable / unparseable PowerDNS = hide newer features.
     */
    public function testUnknownVersionReturnsFalseForAllVisibilityMethods(): void
    {
        $caps = PdnsCapabilities::fromVersion(null);
        $this->assertFalse($caps->prefersPrimarySecondaryTerminology());
        $this->assertFalse($caps->supportsCatalogZones());
        $this->assertFalse($caps->supportsViews());
        $this->assertFalse($caps->supportsRecordType('SVCB'));
        $this->assertFalse($caps->supportsRecordType('ZONEMD'));
        $this->assertFalse($caps->supportsRecordType('WALLET'));
        $this->assertFalse($caps->supportsIndividualRrsetFetch());
        $this->assertFalse($caps->supportsAutoprimariesApi());
        $this->assertFalse($caps->supportsRecordTimestamps());
        $this->assertFalse($caps->supportsDefaultCsk());
        $this->assertFalse($caps->supportsPemKeyImportExport());
        $this->assertFalse($caps->supportsRfc9615Bootstrap());

        // Always-supported record types are still allowed even on unknown.
        $this->assertTrue($caps->supportsRecordType('A'));
        $this->assertTrue($caps->supportsRecordType('CNAME'));
    }
}
