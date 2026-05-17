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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\EmailTemplateService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

#[CoversClass(EmailTemplateService::class)]
class EmailTemplateServiceMfaTimezoneTest extends TestCase
{
    private EmailTemplateService $service;
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(function (string $section, string $key, $default = null) {
            return $default;
        });
        $this->service = new EmailTemplateService($config);
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    #[Test]
    public function testExpireTimeRendersInProvidedTimezone(): void
    {
        // 2026-05-17 12:00:00 UTC
        $expiresAt = 1779451200;

        $berlin = $this->service->renderMfaVerificationEmail('ABC123', $expiresAt, 'Europe/Berlin');
        $tokyo = $this->service->renderMfaVerificationEmail('ABC123', $expiresAt, 'Asia/Tokyo');
        $newYork = $this->service->renderMfaVerificationEmail('ABC123', $expiresAt, 'America/New_York');

        $this->assertStringContainsString('14:00:00', $berlin['text']);
        $this->assertStringContainsString('21:00:00', $tokyo['text']);
        $this->assertStringContainsString('08:00:00', $newYork['text']);
    }

    #[Test]
    public function testExpireTimeFallsBackToServerTimezoneWhenNotProvided(): void
    {
        date_default_timezone_set('Europe/London');
        $expiresAt = 1779451200; // 12:00 UTC == 13:00 BST in May

        $result = $this->service->renderMfaVerificationEmail('XYZ789', $expiresAt);

        $this->assertStringContainsString('13:00:00', $result['text']);
    }
}
