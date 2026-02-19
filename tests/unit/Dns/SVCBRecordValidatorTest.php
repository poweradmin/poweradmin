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

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\SVCBRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the SVCBRecordValidator
 *
 * Verifies compliance with RFC 9460 Section 2.4.1:
 * - Alias Mode (priority = 0): No SvcParams allowed, target cannot be "."
 * - Service Mode (priority > 0): SvcParams allowed, "." target requires params
 */
class SVCBRecordValidatorTest extends TestCase
{
    private SVCBRecordValidator $validator;

    protected function setUp(): void
    {
        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new SVCBRecordValidator($configMock);
    }

    // ========================================================================
    // Alias Mode (priority = 0)
    // ========================================================================

    public function testAliasModeWithValidTarget(): void
    {
        $result = $this->validator->validate('0 svc.example.com', 'host.example.com', '', 3600, 86400);
        $this->assertTrue($result->isValid());
    }

    public function testAliasModeRejectsDotTarget(): void
    {
        $result = $this->validator->validate('0 .', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Alias Mode', $result->getFirstError());
    }

    public function testAliasModeRejectsParams(): void
    {
        $result = $this->validator->validate('0 svc.example.com alpn=h2', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Alias Mode', $result->getFirstError());
    }

    // ========================================================================
    // Service Mode (priority > 0)
    // ========================================================================

    public function testServiceModeWithTargetOnly(): void
    {
        $result = $this->validator->validate('1 svc.example.com', 'host.example.com', '', 3600, 86400);
        $this->assertTrue($result->isValid());
    }

    public function testServiceModeWithParams(): void
    {
        // RFC 9460 test vector: ServiceMode with port param
        $result = $this->validator->validate('16 foo.example.com port=53', 'host.example.com', '', 3600, 86400);
        $this->assertTrue($result->isValid());
    }

    public function testServiceModeWithMultipleParams(): void
    {
        $result = $this->validator->validate('1 . alpn=h2,h3 port=443 ipv4hint=192.0.2.1', 'host.example.com', '', 3600, 86400);
        $this->assertTrue($result->isValid());
    }

    public function testServiceModeDotTargetRequiresParams(): void
    {
        $result = $this->validator->validate('1 .', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Service Mode', $result->getFirstError());
    }

    // ========================================================================
    // Priority validation
    // ========================================================================

    public function testRejectsInvalidPriority(): void
    {
        $result = $this->validator->validate('65536 example.com', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }

    // ========================================================================
    // Parameter validation
    // ========================================================================

    public function testRejectsInvalidParamFormat(): void
    {
        $result = $this->validator->validate('1 example.com alpn:h2', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsDuplicateParams(): void
    {
        $result = $this->validator->validate('1 example.com alpn=h2 alpn=h3', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Duplicate', $result->getFirstError());
    }

    public function testRejectsInvalidPort(): void
    {
        $result = $this->validator->validate('1 example.com port=70000', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsInvalidIpv4Hint(): void
    {
        $result = $this->validator->validate('1 example.com ipv4hint=300.300.300.300', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsInvalidIpv6Hint(): void
    {
        $result = $this->validator->validate('1 example.com ipv6hint=zzzz::1', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }
}
