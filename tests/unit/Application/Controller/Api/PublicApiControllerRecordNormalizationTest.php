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

namespace Poweradmin\Tests\Unit\Application\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;

/**
 * API record writes store names and content as punycode, like the web forms.
 */
#[CoversClass(PublicApiController::class)]
class PublicApiControllerRecordNormalizationTest extends TestCase
{
    private function controller(): object
    {
        $controller = new class extends PublicApiController {
            // Skip the bootstrap (config, DB, API key auth).
            public function __construct()
            {
            }

            public function run(): void
            {
            }

            public function name(string $name, string $zone): string
            {
                return $this->normalizeV2RecordName($name, $zone);
            }

            public function content(string $type, string $content): string
            {
                return $this->formatV2RecordContent($type, $content);
            }
        };

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn($group, $key, $default = null) => $default);
        (new ReflectionClass(BaseController::class))->getProperty('config')->setValue($controller, $config);

        return $controller;
    }

    public static function names(): array
    {
        return [
            'idn label' => ['bücher', 'example.com', 'xn--bcher-kva.example.com'],
            'idn already qualified' => ['bücher.example.com', 'example.com', 'xn--bcher-kva.example.com'],
            'ascii passthrough' => ['www', 'example.com', 'www.example.com'],
            'apex marker' => ['@', 'example.com', 'example.com'],
            'surrounding whitespace' => ['  www ', 'example.com', 'www.example.com'],
        ];
    }

    #[DataProvider('names')]
    public function testNamesAreStoredAsPunycodeWithZoneSuffix(string $name, string $zone, string $expected): void
    {
        $this->assertSame($expected, $this->controller()->name($name, $zone));
    }

    public static function contents(): array
    {
        return [
            'cname target' => ['CNAME', 'bücher.example.com', 'xn--bcher-kva.example.com'],
            'mx target' => ['MX', 'mail.bücher.example.com', 'mail.xn--bcher-kva.example.com'],
            'a record untouched' => ['A', '192.0.2.1', '192.0.2.1'],
            'txt quoted, not converted' => ['TXT', 'bücher', '"bücher"'],
        ];
    }

    #[DataProvider('contents')]
    public function testDomainContentIsStoredAsPunycode(string $type, string $content, string $expected): void
    {
        $this->assertSame($expected, $this->controller()->content($type, $content));
    }
}
