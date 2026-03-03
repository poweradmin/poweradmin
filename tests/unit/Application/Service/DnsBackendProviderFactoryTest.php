<?php

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use PDO;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;

#[CoversClass(DnsBackendProviderFactory::class)]
class DnsBackendProviderFactoryTest extends TestCase
{
    private $mockDb;
    private $mockConfig;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockConfig = $this->createMock(ConfigurationInterface::class);
    }

    public function testDefaultBackendReturnsSqlProvider(): void
    {
        $this->mockConfig->method('get')->willReturnMap([
            ['pdns_api', 'backend', null, 'sql'],
            ['pdns_api', 'url', null, ''],
            ['pdns_api', 'key', null, ''],
            ['pdns_api', 'server_name', null, ''],
            ['database', 'pdns_db_name', null, ''],
        ]);

        $provider = DnsBackendProviderFactory::create($this->mockDb, $this->mockConfig);

        $this->assertInstanceOf(SqlDnsBackendProvider::class, $provider);
        $this->assertFalse($provider->isApiBackend());
    }

    public function testEmptyBackendReturnsSqlProvider(): void
    {
        $this->mockConfig->method('get')->willReturnMap([
            ['pdns_api', 'backend', null, ''],
            ['pdns_api', 'url', null, ''],
            ['pdns_api', 'key', null, ''],
            ['pdns_api', 'server_name', null, ''],
            ['database', 'pdns_db_name', null, ''],
        ]);

        $provider = DnsBackendProviderFactory::create($this->mockDb, $this->mockConfig);

        $this->assertInstanceOf(SqlDnsBackendProvider::class, $provider);
    }

    public function testApiBackendWithCredentialsReturnsApiProvider(): void
    {
        $this->mockConfig->method('get')->willReturnMap([
            ['pdns_api', 'backend', null, 'api'],
            ['pdns_api', 'url', null, 'http://127.0.0.1:8081'],
            ['pdns_api', 'key', null, 'secret-api-key'],
            ['pdns_api', 'server_name', null, 'localhost'],
            ['database', 'pdns_db_name', null, ''],
        ]);

        $provider = DnsBackendProviderFactory::create($this->mockDb, $this->mockConfig);

        $this->assertInstanceOf(ApiDnsBackendProvider::class, $provider);
        $this->assertTrue($provider->isApiBackend());
    }

    public function testApiBackendWithoutUrlFallsBackToSql(): void
    {
        $this->mockConfig->method('get')->willReturnMap([
            ['pdns_api', 'backend', null, 'api'],
            ['pdns_api', 'url', null, ''],
            ['pdns_api', 'key', null, 'secret-api-key'],
            ['pdns_api', 'server_name', null, ''],
            ['database', 'pdns_db_name', null, ''],
        ]);

        $provider = DnsBackendProviderFactory::create($this->mockDb, $this->mockConfig);

        $this->assertInstanceOf(SqlDnsBackendProvider::class, $provider);
    }

    public function testApiBackendWithoutKeyFallsBackToSql(): void
    {
        $this->mockConfig->method('get')->willReturnMap([
            ['pdns_api', 'backend', null, 'api'],
            ['pdns_api', 'url', null, 'http://127.0.0.1:8081'],
            ['pdns_api', 'key', null, ''],
            ['pdns_api', 'server_name', null, ''],
            ['database', 'pdns_db_name', null, ''],
        ]);

        $provider = DnsBackendProviderFactory::create($this->mockDb, $this->mockConfig);

        $this->assertInstanceOf(SqlDnsBackendProvider::class, $provider);
    }

    public function testApiBackendWithCustomServerName(): void
    {
        $this->mockConfig->method('get')->willReturnMap([
            ['pdns_api', 'backend', null, 'api'],
            ['pdns_api', 'url', null, 'http://127.0.0.1:8081'],
            ['pdns_api', 'key', null, 'secret-api-key'],
            ['pdns_api', 'server_name', null, 'custom-server'],
            ['database', 'pdns_db_name', null, ''],
        ]);

        $provider = DnsBackendProviderFactory::create($this->mockDb, $this->mockConfig);

        $this->assertInstanceOf(ApiDnsBackendProvider::class, $provider);
    }

    public function testApiBackendWithEmptyServerNameDefaultsToLocalhost(): void
    {
        $this->mockConfig->method('get')->willReturnMap([
            ['pdns_api', 'backend', null, 'api'],
            ['pdns_api', 'url', null, 'http://127.0.0.1:8081'],
            ['pdns_api', 'key', null, 'secret-api-key'],
            ['pdns_api', 'server_name', null, ''],
            ['database', 'pdns_db_name', null, ''],
        ]);

        $provider = DnsBackendProviderFactory::create($this->mockDb, $this->mockConfig);

        $this->assertInstanceOf(ApiDnsBackendProvider::class, $provider);
    }
}
