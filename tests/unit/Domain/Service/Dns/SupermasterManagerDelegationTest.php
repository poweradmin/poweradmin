<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;
use Poweradmin\Domain\Service\Dns\SupermasterManager;

#[CoversClass(SupermasterManager::class)]
class SupermasterManagerDelegationTest extends TestCase
{
    private $mockDb;
    private $mockConfig;
    private $mockBackendProvider;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockConfig = $this->createMock(ConfigurationManager::class);
        $this->mockConfig->method('get')->willReturnMap([
            ['database', 'pdns_db_name', null, ''],
            ['dns', 'hostmaster', null, 'hostmaster.example.com'],
            ['dns', 'ns1', null, 'ns1.example.com'],
            ['dns', 'ns2', null, 'ns2.example.com'],
            ['dns', 'ns3', null, ''],
            ['dns', 'ns4', null, ''],
            ['idn', 'idn_enabled', null, false],
        ]);
        $this->mockBackendProvider = $this->createMock(DnsBackendProvider::class);
    }

    public function testAddSupermasterDelegatesToBackendProvider(): void
    {
        // Mock the DB query for supermasterIpNameExists check
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(false);
        $this->mockDb->method('prepare')->willReturn($stmt);

        $this->mockBackendProvider->expects($this->once())
            ->method('addSupermaster')
            ->with('192.168.1.1', 'ns1.example.com', 'admin')
            ->willReturn(true);

        $manager = new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->addSupermaster('192.168.1.1', 'ns1.example.com', 'admin');

        $this->assertTrue($result);
    }

    public function testAddSupermasterValidatesIpBeforeDelegation(): void
    {
        $this->mockBackendProvider->expects($this->never())
            ->method('addSupermaster');

        $manager = new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->addSupermaster('not-an-ip', 'ns1.example.com', 'admin');

        $this->assertFalse($result);
    }

    public function testAddSupermasterValidatesHostnameBeforeDelegation(): void
    {
        $this->mockBackendProvider->expects($this->never())
            ->method('addSupermaster');

        $manager = new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->addSupermaster('192.168.1.1', '', 'admin');

        $this->assertFalse($result);
    }

    public function testAddSupermasterValidatesAccountBeforeDelegation(): void
    {
        $this->mockBackendProvider->expects($this->never())
            ->method('addSupermaster');

        $manager = new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->addSupermaster('192.168.1.1', 'ns1.example.com', 'invalid account!');

        $this->assertFalse($result);
    }

    public function testDeleteSupermasterDelegatesToBackendProvider(): void
    {
        $this->mockBackendProvider->expects($this->once())
            ->method('deleteSupermaster')
            ->with('192.168.1.1', 'ns1.example.com')
            ->willReturn(true);

        $manager = new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->deleteSupermaster('192.168.1.1', 'ns1.example.com');

        $this->assertTrue($result);
    }

    public function testValidateAccountAcceptsAlphanumericAndSpecialChars(): void
    {
        $this->assertTrue(SupermasterManager::validateAccount('admin'));
        $this->assertTrue(SupermasterManager::validateAccount('user.name'));
        $this->assertTrue(SupermasterManager::validateAccount('user_name'));
        $this->assertTrue(SupermasterManager::validateAccount('user-name'));
        $this->assertTrue(SupermasterManager::validateAccount('Admin123'));
    }

    public function testValidateAccountRejectsInvalidChars(): void
    {
        $this->assertFalse(SupermasterManager::validateAccount('user name'));
        $this->assertFalse(SupermasterManager::validateAccount('user@name'));
        $this->assertFalse(SupermasterManager::validateAccount(''));
    }
}
