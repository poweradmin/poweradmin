<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\WhoisService;

class WhoisServiceTest extends TestCase
{
    private string $testDataFile;
    private WhoisService $whoisService;

    protected function setUp(): void
    {
        $this->testDataFile = sys_get_temp_dir() . '/whois_servers_test.php';
        $this->createTestDataFile([
            'com' => 'whois.verisign-grs.com',
            'net' => 'whois.verisign-grs.com',
            'org' => 'whois.pir.org',
            'co.uk' => 'whois.nic.uk',
            'uk.com' => 'whois.centralnic.com'
        ]);
        $this->whoisService = new WhoisService($this->testDataFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testDataFile)) {
            unlink($this->testDataFile);
        }
    }

    private function createTestDataFile(array $data): void
    {
        $content = "<?php\nreturn " . var_export($data, true) . ";\n";
        file_put_contents($this->testDataFile, $content);
    }

    public function testGetWhoisServer(): void
    {
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServer('com'));
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServer('net'));
        $this->assertEquals('whois.pir.org', $this->whoisService->getWhoisServer('org'));
        $this->assertEquals('whois.nic.uk', $this->whoisService->getWhoisServer('co.uk'));
        $this->assertEquals('whois.centralnic.com', $this->whoisService->getWhoisServer('uk.com'));
    }

    public function testGetWhoisServerCaseInsensitive(): void
    {
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServer('COM'));
        $this->assertEquals('whois.nic.uk', $this->whoisService->getWhoisServer('Co.Uk'));
    }

    public function testGetWhoisServerWithWhitespace(): void
    {
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServer(' com '));
    }

    public function testGetWhoisServerNonexistent(): void
    {
        $this->assertNull($this->whoisService->getWhoisServer('nonexistent'));
    }

    public function testGetWhoisServerForDomain(): void
    {
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServerForDomain('example.com'));
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServerForDomain('test.net'));
        $this->assertEquals('whois.pir.org', $this->whoisService->getWhoisServerForDomain('nonprofit.org'));
    }

    public function testGetWhoisServerForDomainWithSubdomain(): void
    {
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServerForDomain('sub.example.com'));
    }

    public function testGetWhoisServerForDomainMultiLevelTld(): void
    {
        $this->assertEquals('whois.nic.uk', $this->whoisService->getWhoisServerForDomain('example.co.uk'));
        $this->assertEquals('whois.centralnic.com', $this->whoisService->getWhoisServerForDomain('example.uk.com'));
    }

    public function testGetWhoisServerForDomainInvalid(): void
    {
        $this->assertNull($this->whoisService->getWhoisServerForDomain('invalid'));
        $this->assertNull($this->whoisService->getWhoisServerForDomain('example.nonexistent'));
    }

    public function testHasTld(): void
    {
        $this->assertTrue($this->whoisService->hasTld('com'));
        $this->assertTrue($this->whoisService->hasTld('co.uk'));
        $this->assertFalse($this->whoisService->hasTld('nonexistent'));
        $this->assertTrue($this->whoisService->hasTld('COM'));
        $this->assertTrue($this->whoisService->hasTld(' com '));
    }

    public function testGetAllWhoisServers(): void
    {
        $servers = $this->whoisService->getAllWhoisServers();

        $this->assertCount(5, $servers);
        $this->assertArrayHasKey('com', $servers);
        $this->assertArrayHasKey('net', $servers);
        $this->assertArrayHasKey('org', $servers);
        $this->assertArrayHasKey('co.uk', $servers);
        $this->assertArrayHasKey('uk.com', $servers);
        $this->assertEquals('whois.verisign-grs.com', $servers['com']);
        $this->assertEquals('whois.pir.org', $servers['org']);
    }

    public function testSetSocketTimeout(): void
    {
        $reflectionClass = new \ReflectionClass(WhoisService::class);
        $property = $reflectionClass->getProperty('socketTimeout');
        $property->setAccessible(true);

        $this->assertEquals(10, $property->getValue($this->whoisService));

        $this->whoisService->setSocketTimeout(5);
        $this->assertEquals(5, $property->getValue($this->whoisService));

        $this->whoisService->setSocketTimeout(0);
        $this->assertEquals(1, $property->getValue($this->whoisService));

        $this->whoisService->setSocketTimeout(-10);
        $this->assertEquals(1, $property->getValue($this->whoisService));
    }

    public function testMissingDataFile(): void
    {
        $whoisService = new WhoisService('/path/to/nonexistent/file.php');

        $this->assertNull($whoisService->getWhoisServer('com'));
        $this->assertFalse($whoisService->hasTld('com'));
        $this->assertEmpty($whoisService->getAllWhoisServers());
    }

    public function testRefresh(): void
    {
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServer('com'));

        $this->createTestDataFile([
            'com' => 'modified.whois.server.com',
            'net' => 'whois.verisign-grs.com'
        ]);

        $this->assertTrue($this->whoisService->refresh());
        $this->assertEquals('modified.whois.server.com', $this->whoisService->getWhoisServer('com'));
        $this->assertNull($this->whoisService->getWhoisServer('org'));
    }

    public function testFormatWhoisResponse(): void
    {
        $rawResponse = "Domain Name: EXAMPLE.COM\r\n\r\n\r\nRegistrar: Example Registrar, LLC\r\nCreation Date: 1995-08-14T04:00:00Z\r\n\r\n\r\n\r\nRegistry Expiry Date: 2021-08-13T04:00:00Z";
        $expectedOutput = "Domain Name: EXAMPLE.COM\n\nRegistrar: Example Registrar, LLC\nCreation Date: 1995-08-14T04:00:00Z\n\nRegistry Expiry Date: 2021-08-13T04:00:00Z";

        $this->assertEquals($expectedOutput, $this->whoisService->formatWhoisResponse($rawResponse));
    }

    public function testGetWhoisInfoWithNoServer(): void
    {
        $mockWhoisService = $this->getMockBuilder(WhoisService::class)
            ->setConstructorArgs([$this->testDataFile])
            ->onlyMethods(['getWhoisServerForDomain'])
            ->getMock();

        $mockWhoisService->expects($this->once())
            ->method('getWhoisServerForDomain')
            ->with('example.nonexistent')
            ->willReturn(null);

        $result = $mockWhoisService->getWhoisInfo('example.nonexistent');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('No WHOIS server found for this domain', $result['error']);
    }

    public function testGetWhoisInfoWithQueryFailure(): void
    {
        $mockWhoisService = $this->getMockBuilder(WhoisService::class)
            ->setConstructorArgs([$this->testDataFile])
            ->onlyMethods(['getWhoisServerForDomain', 'query'])
            ->getMock();

        $mockWhoisService->expects($this->once())
            ->method('getWhoisServerForDomain')
            ->with('example.com')
            ->willReturn('whois.verisign-grs.com');

        $mockWhoisService->expects($this->once())
            ->method('query')
            ->with('example.com', 'whois.verisign-grs.com')
            ->willReturn(null);

        $result = $mockWhoisService->getWhoisInfo('example.com');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('Failed to retrieve WHOIS information', $result['error']);
    }

    public function testGetWhoisInfoWithException(): void
    {
        $mockWhoisService = $this->getMockBuilder(WhoisService::class)
            ->setConstructorArgs([$this->testDataFile])
            ->onlyMethods(['getWhoisServerForDomain'])
            ->getMock();

        $mockWhoisService->expects($this->once())
            ->method('getWhoisServerForDomain')
            ->with('example.com')
            ->will($this->throwException(new \Exception('Test exception')));

        $result = $mockWhoisService->getWhoisInfo('example.com');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('Error: Test exception', $result['error']);
    }
}
