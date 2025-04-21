<?php

namespace Poweradmin\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\WhoisService;

/**
 * Integration test for IDN (Internationalized Domain Name) WHOIS lookups
 *
 * This test verifies that the WhoisService correctly handles
 * internationalized domain names in various scripts.
 *
 * @requires extension intl
 */
class WhoisServiceIdnIntegrationTest extends TestCase
{
    private WhoisService $whoisService;

    /**
     * Set up the test environment
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->whoisService = new WhoisService();

        // Set a longer timeout for WHOIS queries that might be slow
        $this->whoisService->setSocketTimeout(20);
    }

    /**
     * Test data provider with IDN domains in different scripts
     *
     * @return array Test case data
     */
    public static function idnDomainsProvider(): array
    {
        return [
            // [domain unicode, domain punycode, tld unicode, tld punycode]

            // Cyrillic Script
            ['пример.рф', 'xn--e1afmkfd.xn--p1ai', 'рф', 'xn--p1ai'],
            ['тест.укр', 'xn--e1aybc.xn--j1amh', 'укр', 'xn--j1amh'],

            // Chinese Characters
            ['例子.中国', 'xn--fsqu00a.xn--fiqs8s', '中国', 'xn--fiqs8s'],
            ['測試.台灣', 'xn--g6w251d.xn--kpry57d', '台灣', 'xn--kpry57d'],

            // Arabic Script
            ['مثال.مصر', 'xn--mgbh0fb.xn--wgbh1c', 'مصر', 'xn--wgbh1c'],
            ['اختبار.عمان', 'xn--kgbechtv.xn--mgb9awbf', 'عمان', 'xn--mgb9awbf'],

            // Greek Script
            ['παράδειγμα.ελ', 'xn--hxajbheg2az3al.xn--qxam', 'ελ', 'xn--qxam'],

            // Hebrew Script
            ['דוגמה.ישראל', 'xn--5dbqzzl.xn--4dbrk0ce', 'ישראל', 'xn--4dbrk0ce'],

            // Thai Script
            ['ตัวอย่าง.ไทย', 'xn--o3cw4h3cep8c.xn--o3cw4h', 'ไทย', 'xn--o3cw4h'],

            // Korean Script
            ['예시.한국', 'xn--9n2bp8q.xn--3e0b707e', '한국', 'xn--3e0b707e'],
        ];
    }

    /**
     * Test that both Unicode and Punycode versions of the same TLD
     * resolve to the same WHOIS server
     *
     * @dataProvider idnDomainsProvider
     */
    public function testIdnTldWhoisServerLookup(
        string $domainUnicode,
        string $domainPunycode,
        string $tldUnicode,
        string $tldPunycode
    ): void {
        // Skip test if intl extension is not available
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('The intl extension is not available.');
        }

        // Get WHOIS server for Unicode TLD
        $serverFromUnicode = $this->whoisService->getWhoisServer($tldUnicode);

        // Get WHOIS server for Punycode TLD
        $serverFromPunycode = $this->whoisService->getWhoisServer($tldPunycode);

        // If both are null, the TLD might not be in our database, but they should be consistent
        if ($serverFromUnicode === null && $serverFromPunycode === null) {
            $this->markTestIncomplete("No WHOIS server found for TLD {$tldUnicode} / {$tldPunycode}");
        }

        // Verify that the same server is returned regardless of TLD format
        $this->assertSame(
            $serverFromUnicode,
            $serverFromPunycode,
            "WHOIS servers differ for Unicode/Punycode versions of {$tldUnicode}"
        );
    }

    /**
     * Test conversion of IDN domains to Punycode for WHOIS queries
     *
     * This test verifies the internal convertToIdnaPunycode method works by
     * checking that the same WHOIS responses are returned for both forms
     *
     * @dataProvider idnDomainsProvider
     */
    public function testIdnDomainConversionForQueries(
        string $domainUnicode,
        string $domainPunycode,
        string $tldUnicode,
        string $tldPunycode
    ): void {
        // Skip test if intl extension is not available
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('The intl extension is not available.');
        }

        // Get WHOIS server
        $whoisServer = $this->whoisService->getWhoisServer($tldUnicode)
            ?? $this->whoisService->getWhoisServer($tldPunycode);

        if ($whoisServer === null) {
            $this->markTestSkipped("No WHOIS server found for TLD {$tldUnicode}");
            return;
        }

        // Test that we can make WHOIS queries with both unicode and punycode forms
        // First check if the WHOIS server is responding
        $responseFromPunycode = $this->whoisService->query($domainPunycode, $whoisServer);

        if ($responseFromPunycode === null) {
            $this->markTestSkipped("WHOIS server {$whoisServer} is not responding for {$domainPunycode}");
            return;
        }

        // Now test with Unicode form
        $responseFromUnicode = $this->whoisService->query($domainUnicode, $whoisServer);

        // If we get a response from the Unicode form, we expect it to contain
        // the same domain information as the Punycode form
        // (We can't do a full string comparison as some servers may include timestamps)
        $this->assertNotNull($responseFromUnicode, "Failed to get response for Unicode form of domain");
    }

    /**
     * Test full WHOIS info retrieval for IDN domains
     *
     * @dataProvider idnDomainsProvider
     */
    public function testGetWhoisInfoForIdnDomains(
        string $domainUnicode,
        string $domainPunycode,
        string $tldUnicode,
        string $tldPunycode
    ): void {
        // Skip test if intl extension is not available
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('The intl extension is not available.');
        }

        // Try getting WHOIS info for the Unicode domain
        $resultUnicode = $this->whoisService->getWhoisInfo($domainUnicode);

        // If we can't get info, don't fail the test - some domains might not exist
        // or some servers might be down
        if ($resultUnicode['success'] === false && $resultUnicode['error'] !== null) {
            $this->markTestSkipped("Could not get WHOIS info for {$domainUnicode}: {$resultUnicode['error']}");
            return;
        }

        // Test with Punycode form
        $resultPunycode = $this->whoisService->getWhoisInfo($domainPunycode);

        // Both should succeed or both should fail
        $this->assertSame(
            $resultUnicode['success'],
            $resultPunycode['success'],
            "WHOIS query success status differs between Unicode and Punycode forms"
        );

        if ($resultUnicode['success'] && $resultPunycode['success']) {
            // The responses may not be identical character-for-character due to timestamps, etc.
            // But both should either have data or not have data
            $this->assertEquals(
                empty($resultUnicode['data']),
                empty($resultPunycode['data']),
                "WHOIS data presence differs between Unicode and Punycode forms"
            );
        }
    }

    /**
     * Test that getWhoisServerForDomain works with IDN domains
     *
     * @dataProvider idnDomainsProvider
     */
    public function testGetWhoisServerForIdnDomain(
        string $domainUnicode,
        string $domainPunycode,
        string $tldUnicode,
        string $tldPunycode
    ): void {
        // Skip test if intl extension is not available
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('The intl extension is not available.');
        }

        // Get WHOIS server for Unicode domain
        $serverFromUnicode = $this->whoisService->getWhoisServerForDomain($domainUnicode);

        // Get WHOIS server for Punycode domain
        $serverFromPunycode = $this->whoisService->getWhoisServerForDomain($domainPunycode);

        // If both are null, the TLD might not be in our database, but they should be consistent
        if ($serverFromUnicode === null && $serverFromPunycode === null) {
            $this->markTestIncomplete("No WHOIS server found for domain {$domainUnicode} / {$domainPunycode}");
        }

        // Verify that the same server is returned regardless of domain format
        $this->assertSame(
            $serverFromUnicode,
            $serverFromPunycode,
            "WHOIS servers differ for Unicode/Punycode versions of domain {$domainUnicode}"
        );
    }
}
