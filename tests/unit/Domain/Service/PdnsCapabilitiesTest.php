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

    #[DataProvider('viewsReasonProvider')]
    public function testViewsUnavailableReason(array $info, ?string $expected): void
    {
        $caps = PdnsCapabilities::fromServerInfo($info);
        $this->assertSame($expected, $caps->viewsUnavailableReason());
        $this->assertSame($expected === null, $caps->supportsViews());
    }

    public static function viewsReasonProvider(): array
    {
        return [
            'lmdb with views=yes' => [['version' => '5.1.4', 'backends' => 'lmdb', 'views' => 'yes'], null],
            'sql backend' => [
                ['version' => '5.1.4', 'backends' => 'gmysql', 'views' => 'no'],
                PdnsCapabilities::VIEWS_NEED_LMDB,
            ],
            'views disabled' => [
                ['version' => '5.1.4', 'backends' => 'lmdb', 'views' => 'no'],
                PdnsCapabilities::VIEWS_NEED_SETTING,
            ],
            'version is reported before the backend' => [
                ['version' => '4.9.12', 'backends' => 'gmysql', 'views' => 'no'],
                PdnsCapabilities::VIEWS_NEED_VERSION,
            ],
            // /config unreachable: no evidence against views, so nothing is hidden.
            'settings unread' => [['version' => '5.1.4'], null],
            'settings empty' => [['version' => '5.1.4', 'backends' => '', 'views' => ''], null],
        ];
    }

    public function testViewsStayVisibleForAVersionOnlyCapabilitySet(): void
    {
        $this->assertTrue(PdnsCapabilities::fromVersion('5.1.4')->supportsViews());
    }

    public function testViewsUnavailableMessageNamesTheMissingPrerequisite(): void
    {
        $message = fn(array $info): ?string => PdnsCapabilities::fromServerInfo($info)->viewsUnavailableMessage();

        $this->assertNull($message(['version' => '5.1.4', 'backends' => 'lmdb', 'views' => 'yes']));
        $this->assertStringContainsString(
            '5.0',
            (string) $message(['version' => '4.9.12'])
        );
        $this->assertStringContainsString(
            'LMDB',
            (string) $message(['version' => '5.1.4', 'backends' => 'gmysql', 'views' => 'yes'])
        );
        $this->assertStringContainsString(
            'views=yes',
            (string) $message(['version' => '5.1.4', 'backends' => 'lmdb', 'views' => 'no'])
        );
    }

    #[DataProvider('launchValueProvider')]
    public function testLaunchListsAndInstanceSuffixesAreParsed(string $launch, bool $expected): void
    {
        $caps = PdnsCapabilities::fromServerInfo([
            'version' => '5.1.4',
            'backends' => $launch,
            'views' => 'yes',
        ]);
        $this->assertSame($expected, $caps->supportsViews());
    }

    public static function launchValueProvider(): array
    {
        return [
            'single' => ['lmdb', true],
            'instance suffix' => ['lmdb:one', true],
            'comma separated' => ['gmysql,lmdb', true],
            'space separated' => ['gmysql lmdb', true],
            'uppercase' => ['LMDB', true],
            'sql only' => ['gmysql', false],
            'lookalike' => ['lmdbx', false],
        ];
    }

    #[DataProvider('viewsSettingProvider')]
    public function testViewsSettingAcceptsPowerDnsBooleanSpellings(string $value, bool $expected): void
    {
        $caps = PdnsCapabilities::fromServerInfo([
            'version' => '5.1.4',
            'backends' => 'lmdb',
            'views' => $value,
        ]);
        $this->assertSame($expected, $caps->supportsViews());
    }

    public static function viewsSettingProvider(): array
    {
        return [
            'yes' => ['yes', true],
            'true' => ['true', true],
            'one' => ['1', true],
            'padded' => [' yes ', true],
            'no' => ['no', false],
            'zero' => ['0', false],
        ];
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
            'RESINFO available from 5.1' => ['5.1.0', 'RESINFO', true],
            'RESINFO unavailable on 5.0' => ['5.0.0', 'RESINFO', false],
            'HHIT available from 5.1' => ['5.1.0', 'HHIT', true],
            'HHIT unavailable on 5.0' => ['5.0.0', 'HHIT', false],
            'BRID available from 5.1' => ['5.1.0', 'BRID', true],
            'BRID unavailable on 5.0' => ['5.0.0', 'BRID', false],
            'lowercase input is normalised' => ['4.4.0', 'svcb', true],
        ];
    }

    public function testApiEndpointGates(): void
    {
        $v45 = PdnsCapabilities::fromVersion('4.5.9');
        $v46 = PdnsCapabilities::fromVersion('4.6.0');
        $v47 = PdnsCapabilities::fromVersion('4.7.0');
        // rrset_name/rrset_type landed in auth-4.7.0, not 4.6
        $this->assertFalse($v46->supportsIndividualRrsetFetch());
        $this->assertTrue($v47->supportsIndividualRrsetFetch());
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
        $this->assertFalse($caps->supportsRecordType('RESINFO'));
        $this->assertFalse($caps->supportsRecordType('HHIT'));
        $this->assertFalse($caps->supportsRecordType('BRID'));
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
