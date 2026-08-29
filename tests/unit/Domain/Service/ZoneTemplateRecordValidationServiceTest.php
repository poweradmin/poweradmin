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

namespace Unit\Domain\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\ZoneTemplateRecordValidationService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Test for zone template record validation
 *
 * Issue #560: template records are not validated, so a template can produce a zone
 * PowerDNS refuses to serve
 * @see https://github.com/poweradmin/poweradmin/issues/560
 */
class ZoneTemplateRecordValidationServiceTest extends TestCase
{
    private ZoneTemplateRecordValidationService $service;

    protected function setUp(): void
    {
        $config = ConfigurationManager::getInstance();
        $config->initialize();

        $db = $this->createMock(PDO::class);

        $this->service = new ZoneTemplateRecordValidationService(
            new DnsValidatorRegistry($config, $db)
        );
    }

    private function validate(string $name, string $type, string $content, int $ttl = 3600, int $prio = 0): bool
    {
        return $this->service->validate($name, $type, $content, $ttl, $prio, 3600)->isValid();
    }

    public function testAcceptsSoaBuiltFromPlaceholders(): void
    {
        $this->assertTrue($this->validate(
            '[ZONE]',
            'SOA',
            '[NS1] [HOSTMASTER] [SERIAL] [SOA_REFRESH] [SOA_RETRY] [SOA_EXPIRE] [SOA_MINIMUM]'
        ));
    }

    public function testAcceptsShortSoaCompletedAtZoneCreation(): void
    {
        // Zone creation appends the configured timers when rdata has fewer than 7 fields
        $this->assertTrue($this->validate('[ZONE]', 'SOA', '[NS1] [HOSTMASTER] [SERIAL]'));
    }

    /**
     * Completion has to pad only the fields that are missing. Appending a fixed four
     * timers pushes a partially-specified SOA past the seven the validator allows,
     * which refused to save a template record that had saved fine before.
     *
     * @dataProvider partialSoaProvider
     */
    public function testAcceptsPartiallySpecifiedSoa(string $content): void
    {
        $this->assertTrue($this->validate('[ZONE]', 'SOA', $content));
    }

    public static function partialSoaProvider(): array
    {
        return [
            'refresh only' => ['[NS1] [HOSTMASTER] [SERIAL] [SOA_REFRESH]'],
            'refresh and retry' => ['[NS1] [HOSTMASTER] [SERIAL] [SOA_REFRESH] [SOA_RETRY]'],
            'all but minimum' => ['[NS1] [HOSTMASTER] [SERIAL] [SOA_REFRESH] [SOA_RETRY] [SOA_EXPIRE]'],
            'literal timers' => ['ns1.example.com. hostmaster.example.com. 2024010101 3600'],
        ];
    }

    public function testRejectsSoaWithNonNumericTimers(): void
    {
        $this->assertFalse($this->validate(
            '[ZONE]',
            'SOA',
            '[NS1] [HOSTMASTER] [SERIAL] not a number at all'
        ));
    }

    public function testRejectsSoaWithGarbageContent(): void
    {
        $this->assertFalse($this->validate('[ZONE]', 'SOA', 'this is not an soa record'));
    }

    public function testAcceptsValidARecord(): void
    {
        $this->assertTrue($this->validate('www.[ZONE]', 'A', '192.0.2.1'));
    }

    public function testRejectsARecordWithNonIpContent(): void
    {
        $this->assertFalse($this->validate('www.[ZONE]', 'A', 'not-an-ip'));
    }

    public function testRejectsARecordWithIpv6Content(): void
    {
        $this->assertFalse($this->validate('www.[ZONE]', 'A', '2001:db8::1'));
    }

    public function testAcceptsValidAaaaRecord(): void
    {
        $this->assertTrue($this->validate('www.[ZONE]', 'AAAA', '2001:db8::1'));
    }

    public function testAcceptsValidMxRecord(): void
    {
        $this->assertTrue($this->validate('[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10));
    }

    public function testRejectsMxRecordWithInvalidTarget(): void
    {
        $this->assertFalse($this->validate('[ZONE]', 'MX', 'not a hostname', 3600, 10));
    }

    public function testAcceptsValidNsRecord(): void
    {
        $this->assertTrue($this->validate('[ZONE]', 'NS', '[NS1]'));
    }

    public function testAcceptsCnameWithoutZoneContext(): void
    {
        // The zone-scoped conflict checks are skipped: a template record has no zone yet
        $this->assertTrue($this->validate('www.[ZONE]', 'CNAME', '[ZONE]'));
    }

    public function testRejectsCnameWithInvalidTarget(): void
    {
        $this->assertFalse($this->validate('www.[ZONE]', 'CNAME', 'not a hostname'));
    }

    public function testAcceptsUnknownPlaceholderWithoutJudging(): void
    {
        // Nothing dependable to check, so the record is stored rather than guessed at
        $this->assertTrue($this->validate('www.[ZONE]', 'A', '[CUSTOM_IP]'));
    }

    public function testRejectsInvalidTtl(): void
    {
        $this->assertFalse($this->validate('www.[ZONE]', 'A', '192.0.2.1', -5));
    }
}
