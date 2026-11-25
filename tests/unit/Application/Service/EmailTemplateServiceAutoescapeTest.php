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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\EmailTemplateService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

class EmailTemplateServiceAutoescapeTest extends TestCase
{
    public function testPlainTextPartIsNotHtmlEscapedAndHtmlPartIs(): void
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, $default = null) => $default);
        $service = new EmailTemplateService($config);

        $mail = $service->renderZoneAccessGrantedEmail('a&b.example.com', 'Tom & Jerry', 'Admin & Co', 'a@example.com', '2026-01-01', 'https://x.example/zones/1/edit');

        $this->assertStringContainsString('a&b.example.com', $mail['text']);
        $this->assertStringContainsString('Admin & Co', $mail['text']);
        $this->assertStringNotContainsString('&amp;', $mail['text']);
        $this->assertStringContainsString('a&amp;b.example.com', $mail['html']);
    }
}
