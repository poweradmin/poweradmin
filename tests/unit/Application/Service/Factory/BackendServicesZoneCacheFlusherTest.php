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

namespace Poweradmin\Tests\Unit\Application\Service\Factory;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Factory\BackendServices;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Infrastructure\Service\PdnsApiZoneCacheFlusher;
use Poweradmin\Infrastructure\Session\ArraySession;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

class BackendServicesZoneCacheFlusherTest extends TestCase
{
    private const API = ['url' => 'http://pdns.example:8081', 'key' => 'secret', 'server_name' => 'localhost'];

    public function testSqlBackendWithAnApiFlushesThroughIt(): void
    {
        $this->assertInstanceOf(PdnsApiZoneCacheFlusher::class, $this->services(['dns' => ['backend' => 'sql'], 'pdns_api' => self::API])->zoneCacheFlusher());
    }

    /**
     * @param array<string, array<string, mixed>> $config
     */
    #[DataProvider('noFlushProvider')]
    public function testNoFlusherWhenPowerDnsNeedsNoTellingOrCannotBeTold(array $config): void
    {
        $this->assertNull($this->services($config)->zoneCacheFlusher());
    }

    public static function noFlushProvider(): array
    {
        return [
            'API backend flushes on its own writes' => [['dns' => ['backend' => 'api'], 'pdns_api' => self::API]],
            'SQL backend without an API' => [['dns' => ['backend' => 'sql']]],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $config
     */
    private function services(array $config): BackendServices
    {
        $config['database'] = ['type' => 'sqlite', 'pdns_db_name' => ''];

        return new BackendServices(
            new PDO('sqlite::memory:'),
            new FakeConfiguration($config),
            new NullLogger(),
            $this->createMock(ActorInterface::class),
            new ArraySession()
        );
    }
}
