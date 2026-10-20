<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\Dns\SupermasterWriteResult;
use Poweradmin\Domain\Service\Validation\Refusal;
use TestHelpers\FakeConfiguration;

#[CoversClass(SupermasterManager::class)]
class SupermasterManagerDelegationTest extends TestCase
{
    private $mockUsers;
    private $mockConfig;
    private $mockBackendProvider;

    protected function setUp(): void
    {
        $this->mockUsers = $this->createMock(UserLookupInterface::class);
        $this->mockConfig = new FakeConfiguration([
            'database' => ['pdns_db_name' => ''],
            'dns' => [
                'hostmaster' => 'hostmaster.example.com',
                'ns1' => 'ns1.example.com',
                'ns2' => 'ns2.example.com',
                'ns3' => '',
                'ns4' => '',
            ],
            'idn' => ['idn_enabled' => false],
        ]);
        $this->mockBackendProvider = $this->createMock(DnsBackendProviderInterface::class);
    }

    public function testAddSupermasterDelegatesToBackendProvider(): void
    {
        $this->mockBackendProvider->expects($this->once())
            ->method('addSupermaster')
            ->with('192.168.1.1', 'ns1.example.com', 'admin')
            ->willReturn(true);

        $manager = new SupermasterManager($this->mockUsers, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->addSupermaster('192.168.1.1', 'ns1.example.com', 'admin');

        $this->assertTrue($result->success);
    }

    public function testAddSupermasterValidatesIpBeforeDelegation(): void
    {
        $this->mockBackendProvider->expects($this->never())
            ->method('addSupermaster');

        $manager = new SupermasterManager($this->mockUsers, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->addSupermaster('not-an-ip', 'ns1.example.com', 'admin');

        $this->assertFalse($result->success);
        $this->assertSame(SupermasterWriteResult::ERR_INVALID_IP, $result->code);
    }

    public function testAddSupermasterValidatesHostnameBeforeDelegation(): void
    {
        $this->mockBackendProvider->expects($this->never())
            ->method('addSupermaster');

        $manager = new SupermasterManager($this->mockUsers, $this->mockConfig, $this->mockBackendProvider);

        $result = $manager->addSupermaster('192.168.1.1', '', 'admin');

        $this->assertFalse($result->success);
        $this->assertSame(SupermasterWriteResult::ERR_INVALID_HOSTNAME, $result->code);
    }

    public function testAddSupermasterValidatesAccountBeforeDelegation(): void
    {
        $this->mockBackendProvider->expects($this->never())
            ->method('addSupermaster');

        $manager = new SupermasterManager($this->mockUsers, $this->mockConfig, $this->mockBackendProvider);

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

        $manager = new SupermasterManager($this->mockUsers, $this->mockConfig, $this->mockBackendProvider);

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

        $result = (new SupermasterManager($this->mockUsers, $this->mockConfig, $this->mockBackendProvider))
            ->addSupermaster('192.168.1.1', 'ns1.example.com', 'admin');

        $this->assertSame(SupermasterWriteResult::ERR_EXISTS, $result->code);
        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    public function testUpdateRefusesAnUnknownSupermasterWith404(): void
    {
        $this->mockBackendProvider->method('getSupermasters')->willReturn([]);
        $this->mockBackendProvider->expects($this->never())->method('updateSupermaster');

        $result = (new SupermasterManager($this->mockUsers, $this->mockConfig, $this->mockBackendProvider))
            ->updateSupermaster('192.168.1.1', 'ns1.example.com', '192.168.1.2', 'ns2.example.com', 'admin');

        $this->assertSame(SupermasterWriteResult::ERR_NOT_FOUND, $result->code);
        $this->assertSame(Refusal::NOT_FOUND, $result->refusal);
    }

    public function testABackendRefusalIsA500(): void
    {
        $this->mockBackendProvider->method('getSupermasters')->willReturn([]);
        $this->mockBackendProvider->method('addSupermaster')->willReturn(false);

        $result = (new SupermasterManager($this->mockUsers, $this->mockConfig, $this->mockBackendProvider))
            ->addSupermaster('192.168.1.1', 'ns1.example.com', 'admin');

        $this->assertSame(SupermasterWriteResult::ERR_BACKEND, $result->code);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result->refusal);
    }

    public function testGetSupermastersResolvesTheAccountFullName(): void
    {
        $this->mockBackendProvider->method('getSupermasters')->willReturn([
            ['master_ip' => '192.168.1.1', 'ns_name' => 'ns1.example.com', 'account' => 'admin'],
            ['master_ip' => '192.168.1.2', 'ns_name' => 'ns2.example.com', 'account' => ''],
            ['master_ip' => '192.168.1.3', 'ns_name' => 'ns3.example.com', 'account' => 'ghost'],
        ]);
        $this->mockUsers->method('getFullNameByUsername')->willReturnMap([['admin', 'Site Admin'], ['ghost', null]]);

        $result = (new SupermasterManager($this->mockUsers, $this->mockConfig, $this->mockBackendProvider))->getSupermasters();

        $this->assertSame([
            ['master_ip' => '192.168.1.1', 'ns_name' => 'ns1.example.com', 'account' => 'admin', 'fullname' => 'Site Admin'],
            ['master_ip' => '192.168.1.2', 'ns_name' => 'ns2.example.com', 'account' => '', 'fullname' => ''],
            ['master_ip' => '192.168.1.3', 'ns_name' => 'ns3.example.com', 'account' => 'ghost', 'fullname' => ''],
        ], $result);
    }
}
