<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\Dns\SupermasterWriteResult;
use Poweradmin\Domain\Service\Validation\Refusal;

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
        $this->mockBackendProvider = $this->createMock(DnsBackendProviderInterface::class);
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

        $this->assertTrue($result->success);
    }

    public function testAddSupermasterValidatesIpBeforeDelegation(): void
    {
        $this->mockBackendProvider->expects($this->never())
            ->method('addSupermaster');

        $manager = new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->addSupermaster('not-an-ip', 'ns1.example.com', 'admin');

        $this->assertFalse($result->success);
        $this->assertSame(SupermasterWriteResult::ERR_INVALID_IP, $result->code);
    }

    public function testAddSupermasterValidatesHostnameBeforeDelegation(): void
    {
        $this->mockBackendProvider->expects($this->never())
            ->method('addSupermaster');

        $manager = new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->addSupermaster('192.168.1.1', '', 'admin');

        $this->assertFalse($result->success);
        $this->assertSame(SupermasterWriteResult::ERR_INVALID_HOSTNAME, $result->code);
    }

    public function testAddSupermasterValidatesAccountBeforeDelegation(): void
    {
        $this->mockBackendProvider->expects($this->never())
            ->method('addSupermaster');

        $manager = new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->addSupermaster('192.168.1.1', 'ns1.example.com', 'invalid account!');

        $this->assertFalse($result->success);
        $this->assertSame(SupermasterWriteResult::ERR_INVALID_ACCOUNT, $result->code);
    }

    public function testDeleteSupermasterDelegatesToBackendProvider(): void
    {
        $this->mockBackendProvider->expects($this->once())
            ->method('deleteSupermaster')
            ->with('192.168.1.1', 'ns1.example.com')
            ->willReturn(true);

        $manager = new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->deleteSupermaster('192.168.1.1', 'ns1.example.com');

        $this->assertTrue($result->success);
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
    public function testAddRefusesADuplicatePairWith409(): void
    {
        $this->mockBackendProvider->method('getSupermasters')->willReturn([['master_ip' => '192.168.1.1', 'ns_name' => 'ns1.example.com', 'account' => 'admin']]);
        $this->mockBackendProvider->expects($this->never())->method('addSupermaster');

        $result = (new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider))
            ->addSupermaster('192.168.1.1', 'ns1.example.com', 'admin');

        $this->assertSame(SupermasterWriteResult::ERR_EXISTS, $result->code);
        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    public function testUpdateRefusesAnUnknownSupermasterWith404(): void
    {
        $this->mockBackendProvider->method('getSupermasters')->willReturn([]);
        $this->mockBackendProvider->expects($this->never())->method('updateSupermaster');

        $result = (new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider))
            ->updateSupermaster('192.168.1.1', 'ns1.example.com', '192.168.1.2', 'ns2.example.com', 'admin');

        $this->assertSame(SupermasterWriteResult::ERR_NOT_FOUND, $result->code);
        $this->assertSame(Refusal::NOT_FOUND, $result->refusal);
    }

    public function testABackendRefusalIsA500(): void
    {
        $this->mockBackendProvider->method('getSupermasters')->willReturn([]);
        $this->mockBackendProvider->method('addSupermaster')->willReturn(false);

        $result = (new SupermasterManager($this->mockDb, $this->mockConfig, $this->mockBackendProvider))
            ->addSupermaster('192.168.1.1', 'ns1.example.com', 'admin');

        $this->assertSame(SupermasterWriteResult::ERR_BACKEND, $result->code);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result->refusal);
    }
}
