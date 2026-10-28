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

namespace Poweradmin\Tests\Unit\Dns;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;

/**
 * API backend CNAME checks ask the search endpoint for the exact name or content (#1813):
 * a substring query shared its 100 slots with every longer name and content, so the
 * conflicting record could be left out and the check passed.
 */
class CNAMERecordValidatorApiExactSearchTest extends TestCase
{
    /** @var list<array{string, string, int, bool}> */
    private array $queries = [];

    private function validator(array $records = []): CNAMERecordValidator
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn(false);

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('searchDnsData')->willReturnCallback(function (string $query, string $type, int $max, bool $exact = false) use ($records): array {
            $this->queries[] = [$query, $type, $max, $exact];
            return ['zones' => [], 'records' => $records];
        });

        return new CNAMERecordValidator($config, $this->createMock(PDO::class), $backend);
    }

    private static function call(CNAMERecordValidator $validator, string $method, mixed ...$args): bool
    {
        return (new \ReflectionMethod(CNAMERecordValidator::class, $method))->invoke($validator, ...$args)->isValid();
    }

    public function testNameChecksAskForTheExactNameWithTheLargeLimit(): void
    {
        $validator = $this->validator();

        $this->assertTrue(self::call($validator, 'validateCnameUnique', 'www.example.com', -1));
        $this->assertTrue($validator->validateCnameExistence('www.example.com', -1)->isValid());

        $this->assertSame([
            ['www.example.com', 'record', 10000, true],
            ['www.example.com', 'record', 10000, true],
        ], $this->queries);
    }

    public function testAnMxPointingAtTheNameIsFoundInEitherStoredForm(): void
    {
        $validator = $this->validator([['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'id' => 'x']]);

        $this->assertFalse(self::call($validator, 'validateCnameName', 'mail.example.com'));
        $this->assertSame([['mail.example.com', 'record', 10000, true]], $this->queries, 'The first form already found it');
    }

    public function testBothContentFormsAreAskedWhenNothingPointsHere(): void
    {
        $this->assertTrue(self::call($this->validator(), 'validateCnameName', 'free.example.com'));

        $this->assertSame([
            ['free.example.com', 'record', 10000, true],
            ['free.example.com.', 'record', 10000, true],
        ], $this->queries);
    }

    public function testAWildcardNameIsNotSentToTheSearchWhichWouldExpandIt(): void
    {
        $this->assertTrue(self::call($this->validator(), 'validateCnameName', '*.example.com'));
        $this->assertSame([], $this->queries);
    }

    public function testTheProviderSendsAnExactQueryWithoutWildcards(): void
    {
        $client = $this->createMock(PowerdnsApiClient::class);
        $client->expects($this->once())->method('searchData')->with('www.example.com', 'record', 10000)->willReturn([]);
        $db = $this->createMock(PDO::class);
        $db->method('query')->willReturn($this->createMock(\PDOStatement::class));

        $provider = new ApiDnsBackendProvider($client, $db, $this->createMock(ConfigurationManager::class), new NullLogger());

        $this->assertSame([], $provider->searchDnsData('www.example.com', 'record', 10000, true)['records']);
    }
}
