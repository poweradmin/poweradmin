<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DynamicDnsHelper;
use Poweradmin\Domain\Model\RecordType;
use PDO;
use PDOStatement;

class DynamicDnsHelperTest extends TestCase
{
    protected function setUp(): void
    {
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $_REQUEST = [];
    }

    public function testStatusExitWithoutVerbose(): void
    {
        ob_start();
        $result = DynamicDnsHelper::statusExit('good');
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertEquals("good\n", $output);
    }

    public function testStatusExitWithVerbose(): void
    {
        $_REQUEST['verbose'] = '1';

        ob_start();
        $result = DynamicDnsHelper::statusExit('good');
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertEquals("Your hostname has been updated.\n", $output);
    }

    public function testStatusExitWithVerboseMultipleWords(): void
    {
        $_REQUEST['verbose'] = '1';

        ob_start();
        $result = DynamicDnsHelper::statusExit('good 192.168.1.1');
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertEquals("Your hostname has been updated.\n", $output);
    }

    public function testStatusExitAllVerboseCodes(): void
    {
        $_REQUEST['verbose'] = '1';

        $test_cases = [
            'badagent' => 'Your user agent is not valid.',
            'badauth' => 'No username available.',
            'badauth2' => 'Invalid username or password.  Authentication failed.',
            'notfqdn' => 'The hostname you specified was not valid.',
            'dnserr' => 'A DNS error has occurred on our end.  We apologize for any inconvenience.',
            '!yours' => 'The specified hostname does not belong to you.',
            'nohost' => 'The specified hostname does not exist.',
            'good' => 'Your hostname has been updated.',
            '911' => 'A critical error has occurred on our end.  We apologize for any inconvenience.',
            'nochg' => 'This update was identical to your last update, so no changes were made to your hostname configuration.',
            'baddbtype' => 'Unsupported database type',
        ];

        foreach ($test_cases as $code => $expected_message) {
            ob_start();
            $result = DynamicDnsHelper::statusExit((string)$code);
            $output = ob_get_clean();

            $this->assertFalse($result, "statusExit should always return false for code: $code");
            $this->assertEquals("$expected_message\n", $output, "Wrong verbose message for code: $code");
        }
    }

    public function testValidIpAddressWithValidIPv4(): void
    {
        $result = DynamicDnsHelper::validIpAddress('192.168.1.1');
        $this->assertEquals(RecordType::A, $result);
    }

    public function testValidIpAddressWithValidIPv6(): void
    {
        $result = DynamicDnsHelper::validIpAddress('2001:db8::1');
        $this->assertEquals(RecordType::AAAA, $result);
    }

    public function testValidIpAddressWithValidIPv6Full(): void
    {
        $result = DynamicDnsHelper::validIpAddress('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->assertEquals(RecordType::AAAA, $result);
    }

    public function testValidIpAddressWithValidIPv6Compressed(): void
    {
        $result = DynamicDnsHelper::validIpAddress('::1');
        $this->assertEquals(RecordType::AAAA, $result);
    }

    public function testValidIpAddressWithInvalidIP(): void
    {
        $invalid_ips = [
            '192.168.1.256',
            '192.168.1',
            '192.168.1.1.1',
            'not.an.ip',
            '',
            '2001:db8::1::2',
            'gggg::1',
            '192.168.1.-1',
            '999.999.999.999'
        ];

        foreach ($invalid_ips as $ip) {
            $result = DynamicDnsHelper::validIpAddress($ip);
            $this->assertEquals(0, $result, "IP address '$ip' should be invalid");
        }
    }

    public function testExtractValidIpsWithSingleIPv4(): void
    {
        $result = DynamicDnsHelper::extractValidIps('192.168.1.1', 'A');
        $this->assertEquals(['192.168.1.1'], $result);
    }

    public function testExtractValidIpsWithMultipleIPv4(): void
    {
        $result = DynamicDnsHelper::extractValidIps('192.168.1.1,10.0.0.1,172.16.0.1', 'A');
        $this->assertEquals(['192.168.1.1', '10.0.0.1', '172.16.0.1'], $result);
    }

    public function testExtractValidIpsWithSingleIPv6(): void
    {
        $result = DynamicDnsHelper::extractValidIps('2001:db8::1', 'AAAA');
        $this->assertEquals(['2001:db8::1'], $result);
    }

    public function testExtractValidIpsWithMultipleIPv6(): void
    {
        $result = DynamicDnsHelper::extractValidIps('2001:db8::1,::1,2001:db8::2', 'AAAA');
        $this->assertEquals(['2001:db8::1', '::1', '2001:db8::2'], $result);
    }

    public function testExtractValidIpsWithMixedTypesFilteredForIPv4(): void
    {
        $result = DynamicDnsHelper::extractValidIps('192.168.1.1,2001:db8::1,10.0.0.1', 'A');
        $this->assertEquals(['192.168.1.1', '10.0.0.1'], array_values($result));
    }

    public function testExtractValidIpsWithMixedTypesFilteredForIPv6(): void
    {
        $result = DynamicDnsHelper::extractValidIps('192.168.1.1,2001:db8::1,::1', 'AAAA');
        $this->assertEquals(['2001:db8::1', '::1'], array_values($result));
    }

    public function testExtractValidIpsWithInvalidIPs(): void
    {
        $result = DynamicDnsHelper::extractValidIps('192.168.1.1,invalid.ip,10.0.0.1', 'A');
        $this->assertEquals(['192.168.1.1', '10.0.0.1'], array_values($result));
    }

    public function testExtractValidIpsWithWhitespace(): void
    {
        $result = DynamicDnsHelper::extractValidIps(' 192.168.1.1 , 10.0.0.1 , 172.16.0.1 ', 'A');
        $this->assertEquals(['192.168.1.1', '10.0.0.1', '172.16.0.1'], $result);
    }

    public function testExtractValidIpsWithEmptyString(): void
    {
        $result = DynamicDnsHelper::extractValidIps('', 'A');
        $this->assertEquals([], $result);
    }

    public function testExtractValidIpsWithOnlyCommas(): void
    {
        $result = DynamicDnsHelper::extractValidIps(',,,', 'A');
        $this->assertEquals([], $result);
    }

    public function testSyncDnsRecordsNoChanges(): void
    {
        $mockDb = $this->createMock(PDO::class);
        $mockStatement = $this->createMock(PDOStatement::class);

        $mockStatement->expects($this->once())
            ->method('execute')
            ->with([':domain_id' => 1, ':hostname' => 'test.example.com', ':type' => 'A']);

        $mockStatement->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'content' => '192.168.1.1'],
                false
            );

        $mockDb->expects($this->once())
            ->method('prepare')
            ->with("SELECT id, content FROM records WHERE domain_id = :domain_id AND name = :hostname AND type = :type")
            ->willReturn($mockStatement);

        $result = DynamicDnsHelper::syncDnsRecords(
            $mockDb,
            'records',
            1,
            'test.example.com',
            'A',
            ['192.168.1.1']
        );

        $this->assertFalse($result);
    }

    public function testSyncDnsRecordsAddNewRecord(): void
    {
        $mockDb = $this->createMock(PDO::class);
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockInsertStatement = $this->createMock(PDOStatement::class);

        $mockSelectStatement->expects($this->once())
            ->method('execute')
            ->with([':domain_id' => 1, ':hostname' => 'test.example.com', ':type' => 'A']);

        $mockSelectStatement->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $mockInsertStatement->expects($this->once())
            ->method('execute')
            ->with([
                ':domain_id' => 1,
                ':hostname' => 'test.example.com',
                ':type' => 'A',
                ':ip' => '192.168.1.1'
            ]);

        $mockDb->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function ($sql) use ($mockSelectStatement, $mockInsertStatement) {
                if (str_contains($sql, 'SELECT')) {
                    return $mockSelectStatement;
                } else {
                    return $mockInsertStatement;
                }
            });

        $result = DynamicDnsHelper::syncDnsRecords(
            $mockDb,
            'records',
            1,
            'test.example.com',
            'A',
            ['192.168.1.1']
        );

        $this->assertTrue($result);
    }

    public function testSyncDnsRecordsDeleteOldRecord(): void
    {
        $mockDb = $this->createMock(PDO::class);
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockDeleteStatement = $this->createMock(PDOStatement::class);

        $mockSelectStatement->expects($this->once())
            ->method('execute')
            ->with([':domain_id' => 1, ':hostname' => 'test.example.com', ':type' => 'A']);

        $mockSelectStatement->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'content' => '192.168.1.1'],
                false
            );

        $mockDeleteStatement->expects($this->once())
            ->method('execute')
            ->with([':id' => 1]);

        $mockDb->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function ($sql) use ($mockSelectStatement, $mockDeleteStatement) {
                if (str_contains($sql, 'SELECT')) {
                    return $mockSelectStatement;
                } else {
                    return $mockDeleteStatement;
                }
            });

        $result = DynamicDnsHelper::syncDnsRecords(
            $mockDb,
            'records',
            1,
            'test.example.com',
            'A',
            []
        );

        $this->assertTrue($result);
    }

    public function testSyncDnsRecordsComplexScenario(): void
    {
        $mockDb = $this->createMock(PDO::class);
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockInsertStatement = $this->createMock(PDOStatement::class);
        $mockDeleteStatement = $this->createMock(PDOStatement::class);

        $mockSelectStatement->expects($this->once())
            ->method('execute')
            ->with([':domain_id' => 1, ':hostname' => 'test.example.com', ':type' => 'A']);

        $mockSelectStatement->expects($this->exactly(3))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'content' => '192.168.1.1'],
                ['id' => 2, 'content' => '192.168.1.2'],
                false
            );

        $mockInsertStatement->expects($this->once())
            ->method('execute')
            ->with([
                ':domain_id' => 1,
                ':hostname' => 'test.example.com',
                ':type' => 'A',
                ':ip' => '192.168.1.3'
            ]);

        $mockDeleteStatement->expects($this->once())
            ->method('execute')
            ->with([':id' => 2]);

        $mockDb->expects($this->exactly(3))
            ->method('prepare')
            ->willReturnCallback(function ($sql) use ($mockSelectStatement, $mockInsertStatement, $mockDeleteStatement) {
                if (str_contains($sql, 'SELECT')) {
                    return $mockSelectStatement;
                } elseif (str_contains($sql, 'INSERT')) {
                    return $mockInsertStatement;
                } else {
                    return $mockDeleteStatement;
                }
            });

        $result = DynamicDnsHelper::syncDnsRecords(
            $mockDb,
            'records',
            1,
            'test.example.com',
            'A',
            ['192.168.1.1', '192.168.1.3']
        );

        $this->assertTrue($result);
    }

    public function testSyncDnsRecordsWithIPv6(): void
    {
        $mockDb = $this->createMock(PDO::class);
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockInsertStatement = $this->createMock(PDOStatement::class);

        $mockSelectStatement->expects($this->once())
            ->method('execute')
            ->with([':domain_id' => 1, ':hostname' => 'test.example.com', ':type' => 'AAAA']);

        $mockSelectStatement->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $mockInsertStatement->expects($this->once())
            ->method('execute')
            ->with([
                ':domain_id' => 1,
                ':hostname' => 'test.example.com',
                ':type' => 'AAAA',
                ':ip' => '2001:db8::1'
            ]);

        $mockDb->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function ($sql) use ($mockSelectStatement, $mockInsertStatement) {
                if (str_contains($sql, 'SELECT')) {
                    return $mockSelectStatement;
                } else {
                    return $mockInsertStatement;
                }
            });

        $result = DynamicDnsHelper::syncDnsRecords(
            $mockDb,
            'records',
            1,
            'test.example.com',
            'AAAA',
            ['2001:db8::1']
        );

        $this->assertTrue($result);
    }
}
