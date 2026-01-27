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
use Poweradmin\Application\Service\RdapService;
use ReflectionClass;
use ReflectionMethod;

class RdapServiceTest extends TestCase
{
    private string $testDataFile;
    private RdapService $rdapService;

    protected function setUp(): void
    {
        $this->testDataFile = sys_get_temp_dir() . '/rdap_servers_test.php';
        $this->createTestDataFile([
            'com' => 'https://rdap.verisign.com/com/v1/',
            'net' => 'https://rdap.verisign.com/net/v1/',
            'org' => 'https://rdap.identitydigital.services/rdap/',
            'example' => 'https://example.rdap.server/',
            'test' => 'http://test.rdap.server:8080/',
        ]);
        $this->rdapService = new RdapService($this->testDataFile);
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

    private function getValidationMethod(): ReflectionMethod
    {
        $reflection = new ReflectionClass($this->rdapService);
        $method = $reflection->getMethod('isValidRdapUrl');
        $method->setAccessible(true);
        return $method;
    }

    public function testValidRdapUrls(): void
    {
        $method = $this->getValidationMethod();

        $validUrls = [
            'https://rdap.verisign.com/com/v1/domain/example.com',
            'https://rdap.verisign.com/net/v1/domain/test.net',
            'https://rdap.identitydigital.services/rdap/domain/example.org',
            'https://example.rdap.server/domain/test.example',
            'http://test.rdap.server:8080/domain/example.test',
        ];

        foreach ($validUrls as $url) {
            $this->assertTrue(
                $method->invoke($this->rdapService, $url),
                "URL should be valid: $url"
            );
        }
    }

    public function testInvalidRdapUrls(): void
    {
        $method = $this->getValidationMethod();

        $invalidUrls = [
            'https://rdap.verisign.com/com/v1/../../../etc/passwd',
            'https://rdap.verisign.com/com/v1/domain/../../secrets',
            'ftp://rdap.verisign.com/com/v1/domain/example.com',
            'file:///etc/passwd',
            'javascript:alert(1)',
            'not-a-url',
            'http://',
            'https://',
            '',
            'https://rdap.verisign.com/com/v1\\..\\..\\etc\\passwd',
        ];

        foreach ($invalidUrls as $url) {
            $this->assertFalse(
                $method->invoke($this->rdapService, $url),
                "URL should be invalid: $url"
            );
        }
    }

    public function testGetRdapServer(): void
    {
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServer('com'));
        $this->assertEquals('https://rdap.identitydigital.services/rdap/', $this->rdapService->getRdapServer('org'));
        $this->assertNull($this->rdapService->getRdapServer('nonexistent'));
    }

    public function testGetRdapServerForDomain(): void
    {
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServerForDomain('example.com'));
        $this->assertEquals('https://rdap.identitydigital.services/rdap/', $this->rdapService->getRdapServerForDomain('test.org'));
        $this->assertNull($this->rdapService->getRdapServerForDomain('invalid'));
    }

    public function testHasTld(): void
    {
        $this->assertTrue($this->rdapService->hasTld('com'));
        $this->assertTrue($this->rdapService->hasTld('org'));
        $this->assertFalse($this->rdapService->hasTld('nonexistent'));
    }

    public function testGetAllRdapServers(): void
    {
        $servers = $this->rdapService->getAllRdapServers();

        $this->assertCount(5, $servers);
        $this->assertArrayHasKey('com', $servers);
        $this->assertArrayHasKey('org', $servers);
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $servers['com']);
    }

    public function testCaseSensitivityHandling(): void
    {
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServer('COM'));
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServer('Com'));
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServerForDomain('Example.COM'));
    }

    public function testSetRequestTimeout(): void
    {
        $reflectionClass = new ReflectionClass($this->rdapService);
        $property = $reflectionClass->getProperty('requestTimeout');
        $property->setAccessible(true);

        $this->assertEquals(10, $property->getValue($this->rdapService));

        $this->rdapService->setRequestTimeout(15);
        $this->assertEquals(15, $property->getValue($this->rdapService));

        $this->rdapService->setRequestTimeout(0);
        $this->assertEquals(1, $property->getValue($this->rdapService));

        $this->rdapService->setRequestTimeout(-5);
        $this->assertEquals(1, $property->getValue($this->rdapService));
    }

    public function testMissingDataFile(): void
    {
        $rdapService = new RdapService('/path/to/nonexistent/file.php');

        $this->assertNull($rdapService->getRdapServer('com'));
        $this->assertFalse($rdapService->hasTld('com'));
        $this->assertEmpty($rdapService->getAllRdapServers());
    }

    public function testRefresh(): void
    {
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServer('com'));

        $this->createTestDataFile([
            'com' => 'https://modified.rdap.server.com/',
            'net' => 'https://rdap.verisign.com/net/v1/'
        ]);

        $this->assertTrue($this->rdapService->refresh());
        $this->assertEquals('https://modified.rdap.server.com/', $this->rdapService->getRdapServer('com'));
        $this->assertNull($this->rdapService->getRdapServer('org'));
    }

    public function testGetRdapInfoWithNoServer(): void
    {
        $this->createTestDataFile([]);
        $emptyService = new RdapService($this->testDataFile);

        $result = $emptyService->getRdapInfo('example.nonexistent');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('No RDAP server found for this domain', $result['error']);
    }

    public function testFormatRdapResponse(): void
    {
        $testResponse = [
            'objectClassName' => 'domain',
            'handle' => 'EXAMPLE-COM',
            'ldhName' => 'example.com',
            'status' => ['client transfer prohibited', 'client update prohibited'],
        ];

        $formattedResponse = $this->rdapService->formatRdapResponse($testResponse);

        $decodedResponse = json_decode($formattedResponse, true);
        $this->assertEquals($testResponse, $decodedResponse);
        $this->assertStringContainsString('    ', $formattedResponse);
        $this->assertStringNotContainsString('\/', $formattedResponse);
    }

    public function testWhitespaceHandling(): void
    {
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServerForDomain(' example.com '));
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServer(' COM '));
        $this->assertTrue($this->rdapService->hasTld(' com '));
    }

    public function testSubdomainHandling(): void
    {
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServerForDomain('sub.example.com'));
        $this->assertEquals('https://rdap.identitydigital.services/rdap/', $this->rdapService->getRdapServerForDomain('deep.sub.example.org'));
    }

    public function testInvalidDomainInput(): void
    {
        $this->assertNull($this->rdapService->getRdapServerForDomain('invalid'));
        $this->assertNull($this->rdapService->getRdapServerForDomain(''));
        $this->assertNull($this->rdapService->getRdapServerForDomain('example.nonexistent'));
    }

    public function testSecurityAgainstPathTraversal(): void
    {
        $method = $this->getValidationMethod();

        $pathTraversalAttempts = [
            'https://rdap.verisign.com/com/v1/domain/../../../etc/passwd',
            'https://rdap.verisign.com/com/v1/domain/....//....//etc/passwd',
            'https://rdap.verisign.com/com/v1/domain/\..\..\windows\system32\config\sam',
        ];

        foreach ($pathTraversalAttempts as $maliciousUrl) {
            $this->assertFalse(
                $method->invoke($this->rdapService, $maliciousUrl),
                "Path traversal attempt should be blocked: $maliciousUrl"
            );
        }
    }
}
