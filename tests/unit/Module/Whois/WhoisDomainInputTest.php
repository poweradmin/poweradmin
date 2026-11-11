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

namespace Poweradmin\Tests\Unit\Module\Whois;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Module\Whois\Controller\WhoisController;
use Poweradmin\Tests\Unit\Application\Controller\ControllerTestStubs;
use ReflectionMethod;
use RuntimeException;

/**
 * A domain with a control character must not reach the WHOIS socket.
 */
class WhoisDomainInputTest extends TestCase
{
    private string $method;

    protected function setUp(): void
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_METHOD'] = $this->method;
    }

    public static function controlCharacterDomains(): array
    {
        return [
            'line break' => ["example.com\r\nHELP"],
            'line feed' => ["example.com\nHELP"],
            'nul' => ["exam\0ple.com"],
            'inner tab' => ["exam\tple.com"],
            'delete' => ["example\x7F.com"],
        ];
    }

    #[DataProvider('controlCharacterDomains')]
    public function testDomainWithControlCharactersIsRefused(string $domain): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid or unexpected input given.');

        $this->handle($domain);
    }

    public function testPlainDomainIsAccepted(): void
    {
        $this->assertSame('example.com', $this->handle("  example.com\r\n"));
    }

    private function handle(string $domain): string
    {
        $controller = new class (['domain' => $domain], $this->createMock(PDO::class), $this->createMock(ConfigurationManager::class)) extends WhoisController {
            use ControllerTestStubs;
        };

        return (new ReflectionMethod(WhoisController::class, 'handleDomainInput'))->invoke($controller);
    }
}
