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
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\EmailTemplateService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

#[CoversClass(EmailTemplateService::class)]
class EmailTemplateServiceEscapingTest extends TestCase
{
    public function testThePlainTextPartKeepsAmpersandsAndTheHtmlPartEscapesThem(): void
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, $default = null) => $default);

        $mail = (new EmailTemplateService($config))->renderNewAccountEmail('a&b', 'p&w', 'Tom & Jerry');

        $this->assertStringContainsString('Hello Tom & Jerry', $mail['text']);
        $this->assertStringNotContainsString('&amp;', $mail['text']);
        $this->assertStringContainsString('Tom &amp; Jerry', $mail['html']);
    }
}
