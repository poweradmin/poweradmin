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

namespace Poweradmin\Tests\Unit\Module\Whois\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\BaseController;
use Poweradmin\Module\Whois\Controller\WhoisController;
use Poweradmin\Module\Whois\Service\WhoisService;
use ReflectionClass;

class WhoisControllerTest extends TestCase
{
    private string $savedMethod = '';

    protected function setUp(): void
    {
        $this->savedMethod = $_SERVER['REQUEST_METHOD'] ?? '';
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_METHOD'] = $this->savedMethod;
    }

    public function testControlCharactersInTheDomainAreRefusedBeforeAnyQuery(): void
    {
        $service = $this->createMock(WhoisService::class);
        $service->expects($this->never())->method('query');
        $service->expects($this->never())->method('getWhoisInfo');

        $result = $this->lookup("example.com\r\nevil.example", $service);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid domain format', $result['error']);
    }

    private function lookup(string $domain, WhoisService $service): array
    {
        $class = new ReflectionClass(WhoisController::class);
        $controller = $class->newInstanceWithoutConstructor();
        $class->getProperty('whoisService')->setValue($controller, $service);
        (new ReflectionClass(BaseController::class))->getProperty('requestData')
            ->setValue($controller, ['domain' => $domain]);

        $input = $class->getMethod('handleDomainInput');
        $found = $input->invoke($controller);
        $this->assertStringNotContainsString("\r", $found);

        return $class->getMethod('performWhoisLookup')->invoke($controller, $found);
    }
}
