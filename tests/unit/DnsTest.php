<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Infrastructure\Database\PDOLayer;

class DnsTest extends TestCase
{
    private Dns $dnsInstance;

    protected function setUp(): void
    {
        $dbMock = $this->createMock(PDOLayer::class);
        $configMock = $this->createMock(AppConfiguration::class);

        $this->dnsInstance = new Dns($dbMock, $configMock);
    }

    public function testProperlyQuotedString()
    {
        $this->assertTrue(Dns::is_properly_quoted('"This is a \"properly\" quoted string."'));
    }

    public function testStringWithoutQuotes()
    {
        $this->assertTrue(Dns::is_properly_quoted('This string has no quotes'));
    }

    public function testEmptyString()
    {
        $this->assertTrue(Dns::is_properly_quoted(''));
    }

//    public function testStartsWithQuoteOnly()
//    {
//        $this->assertFalse(Dns::is_properly_quoted('"Improperly quoted'));
//    }

//    public function testEndsWithQuoteOnly()
//    {
//        $this->assertFalse(Dns::is_properly_quoted('Improperly quoted"'));
//    }

//    public function testUnescapedInternalQuote()
//    {
//        $this->assertFalse(Dns::is_properly_quoted('"This is "improperly" quoted"'));
//    }

    public function testProperlyEscapedInternalQuote()
    {
        $this->assertTrue(Dns::is_properly_quoted('"This is \"properly\" quoted"'));
    }

    public function testIsValidRrSoaContentWithValidData()
    {
        $content = "example.com hostmaster.example.com 2023122505 7200 1209600 3600 86400";
        $dns_hostmaster = "hostmaster@example.com";
        $this->assertTrue($this->dnsInstance->is_valid_rr_soa_content($content, $dns_hostmaster));
    }

    public function testIsValidRrSoaContentWithValidNumber()
    {
        $content = "example.com hostmaster.example.com 5 7200 1209600 3600 86400";
        $dns_hostmaster = "hostmaster@example.com";
        $this->assertTrue($this->dnsInstance->is_valid_rr_soa_content($content, $dns_hostmaster));
    }

//    public function testIsValidRrSoaContentWithEmptyContent()
//    {
//        $content = "";
//        $dns_hostmaster = "hostmaster@example.com";
//        $this->assertFalse($this->dnsInstance->is_valid_rr_soa_content($content, $dns_hostmaster));
//    }

    public function testIsValidRrSoaContentWithMoreThanSevenFields()
    {
        $content = "example.com hostmaster.example.com 2023122505 7200 1209600 3600 86400 extraField";
        $dns_hostmaster = "hostmaster@example.com";
        $this->assertFalse($this->dnsInstance->is_valid_rr_soa_content($content, $dns_hostmaster));
    }

    public function testIsValidRrSoaContentWithLessThanSevenFields()
    {
        $content = "example.com hostmaster.example.com 2023122505 7200 1209600";
        $dns_hostmaster = "hostmaster@example.com";
        $this->assertFalse($this->dnsInstance->is_valid_rr_soa_content($content, $dns_hostmaster));
    }

//    public function testIsValidRrSoaContentWithInvalidHostname()
//    {
//        $content = "invalid_hostname hostmaster.example.com 2023122505 7200 1209600 3600 86400";
//        $dns_hostmaster = "hostmaster@example.com";
//        $this->assertFalse($this->dnsInstance->is_valid_rr_soa_content($content, $dns_hostmaster));
//    }

    public function testIsValidRrSoaContentWithInvalidEmail()
    {
        $content = "example.com invalid_email 2023122505 7200 1209600 3600 86400";
        $dns_hostmaster = "invalid_email";
        $this->assertFalse($this->dnsInstance->is_valid_rr_soa_content($content, $dns_hostmaster));
    }

    public function testIsValidRrSoaContentWithNonNumericSerialNumbers()
    {
        $content = "example.com hostmaster.example.com not_a_number 7200 1209600 3600 86400";
        $dns_hostmaster = "hostmaster@example.com";
        $this->assertFalse($this->dnsInstance->is_valid_rr_soa_content($content, $dns_hostmaster));
    }

    public function testIsValidRrSoaContentWithArpaDomain()
    {
        $content = "example.arpa hostmaster.example.com 2023122505 7200 1209600 3600 86400";
        $dns_hostmaster = "hostmaster@example.com";
        $this->assertFalse($this->dnsInstance->is_valid_rr_soa_content($content, $dns_hostmaster));
    }

    public function testIsValidDS()
    {
        $this->assertTrue(Dns::is_valid_ds("45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0"));
        $this->assertTrue(Dns::is_valid_ds("2371 13 2 1F987CC6583E92DF0890718C42"));
        $this->assertTrue(Dns::is_valid_ds("15288 5 2 CE0EB9E59EE1DE2C681A330E3A7C08376F28602CDF990EE4EC88D2A8BDB51539"));

        $this->assertFalse(Dns::is_valid_ds("45342 13 2 348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0;"));
        $this->assertFalse(Dns::is_valid_ds("2371 13 2 1F987CC6583E92DF0890718C42 ; ( SHA1 digest )"));
        $this->assertFalse(Dns::is_valid_ds("invalid"));
    }

    public function testIsValidLocation()
    {
        $this->assertTrue(Dns::is_valid_loc('37 23 30.900 N 121 59 19.000 W 7.00m 100.00m 100.00m 2.00m'));
        $this->assertTrue(Dns::is_valid_loc('42 21 54 N 71 06 18 W -24m 30m'));
        $this->assertTrue(Dns::is_valid_loc('42 21 43.952 N 71 5 6.344 W -24m 1m 200m'));
        $this->assertTrue(Dns::is_valid_loc('52 14 05 N 00 08 50 E 10m'));
        $this->assertTrue(Dns::is_valid_loc('32 7 19 S 116 2 25 E 10m'));
        $this->assertTrue(Dns::is_valid_loc('42 21 28.764 N 71 00 51.617 W -44m 2000m'));
        $this->assertTrue(Dns::is_valid_loc('90 59 59.9 N 10 18 E 42849671.91m 1m'));
        $this->assertTrue(Dns::is_valid_loc('9 10 S 12 22 33.4 E -100000.00m 2m 34 3m'));

        # hp precision too high
        $this->assertFalse(Dns::is_valid_loc('37 23 30.900 N 121 59 19.000 W 7.00m 100.00m 100.050m 2.00m'));

        # S is no long.
        $this->assertFalse(Dns::is_valid_loc('42 21 54 N 71 06 18 S -24m 30m'));

        # s2 precision too high
        $this->assertFalse(Dns::is_valid_loc('42 21 43.952 N 71 5 6.4344 W -24m 1m 200m'));

        # s2 maxes to 59.99
        $this->assertFalse(Dns::is_valid_loc('52 14 05 N 00 08 60 E 10m'));

        # long. maxes to 180
        $this->assertFalse(Dns::is_valid_loc('32 7 19 S 186 2 25 E 10m'));

        # lat. maxed to 90
//      $this->assertFalse(is_valid_loc('92 21 28.764 N 71 00 51.617 W -44m 2000m'));
        # alt maxes to 42849672.95
        $this->assertFalse(Dns::is_valid_loc('90 59 59.9 N 10 18 E 42849672.96m 1m'));

        # alt maxes to -100000.00
        $this->assertFalse(Dns::is_valid_loc('9 10 S 12 22 33.4 E -110000.00m 2m 34 3m'));
    }

    public function testIsValidRRPrio()
    {
        $this->assertTrue(Dns::is_valid_rr_prio(10, "MX"));
        $this->assertTrue(Dns::is_valid_rr_prio(65535, "SRV"));
        $this->assertFalse(Dns::is_valid_rr_prio(-1, "MX"));
        $this->assertFalse(Dns::is_valid_rr_prio("foo", "SRV"));
        $this->assertFalse(Dns::is_valid_rr_prio(10, "A"));
        $this->assertFalse(Dns::is_valid_rr_prio("foo", "A"));
        $this->assertTrue(Dns::is_valid_rr_prio("0", "A"));
    }

    public function testIsValidAPL()
    {
        // Valid APL records - matching PowerDNS test cases
        // IPv4 single entries
        $this->assertTrue(Dns::is_valid_apl('1:10.0.0.0/32', false));
        $this->assertTrue(Dns::is_valid_apl('1:10.1.1.1/32', false));
        $this->assertTrue(Dns::is_valid_apl('1:10.1.1.0/24', false));
        $this->assertTrue(Dns::is_valid_apl('1:60.0.0.0/8', false));
        $this->assertTrue(Dns::is_valid_apl('1:255.255.255.255/32', false));

        // IPv4 with negation
        $this->assertTrue(Dns::is_valid_apl('!1:10.1.1.1/32', false));

        // IPv6 single entries
        $this->assertTrue(Dns::is_valid_apl('2:100::/8', false));
        $this->assertTrue(Dns::is_valid_apl('2:20::/16', false));
        $this->assertTrue(Dns::is_valid_apl('2:2000::/8', false));
        $this->assertTrue(Dns::is_valid_apl('2:fe00::/8', false));
        $this->assertTrue(Dns::is_valid_apl('2:fe80::/16', false));
        $this->assertTrue(Dns::is_valid_apl('2:2001:db8::/32', false));
        $this->assertTrue(Dns::is_valid_apl('2:2001:db8::/30', false));
        $this->assertTrue(Dns::is_valid_apl('2:2001::1/128', false));
        $this->assertTrue(Dns::is_valid_apl('2:2001:db8:5678:9910:8bc:3359:b2e8:720e/128', false));
        $this->assertTrue(Dns::is_valid_apl('2:ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff/128', false));

        // IPv6 with negation
        $this->assertTrue(Dns::is_valid_apl('!2:2001::1/128', false));

        // Empty APL (valid per RFC 3123)
        $this->assertTrue(Dns::is_valid_apl('', false));

        // Multiple entries
        $this->assertTrue(Dns::is_valid_apl('1:10.0.0.0/32 1:10.1.1.1/32', false));
        $this->assertTrue(Dns::is_valid_apl('1:10.0.0.0/32 2:100::/8', false));

        // Invalid: bad format
        $this->assertFalse(Dns::is_valid_apl('invalid', false));
        $this->assertFalse(Dns::is_valid_apl('1:10.0.0.0', false)); // Missing prefix
        $this->assertFalse(Dns::is_valid_apl('10.0.0.0/32', false)); // Missing family

        // Invalid: bad IP addresses
        $this->assertFalse(Dns::is_valid_apl('1:999.0.0.0/32', false)); // Invalid IPv4
        $this->assertFalse(Dns::is_valid_apl('2:gggg::/32', false)); // Invalid IPv6

        // Invalid: prefix out of range
        $this->assertFalse(Dns::is_valid_apl('1:10.0.0.0/33', false)); // IPv4 prefix > 32
        $this->assertFalse(Dns::is_valid_apl('1:10.0.0.0/-1', false)); // Negative prefix
        $this->assertFalse(Dns::is_valid_apl('2:2001::/129', false)); // IPv6 prefix > 128

        // Invalid: unknown address family
        $this->assertFalse(Dns::is_valid_apl('3:10.0.0.0/32', false)); // Family 3 doesn't exist
        $this->assertFalse(Dns::is_valid_apl('0:10.0.0.0/32', false)); // Family 0 doesn't exist
    }

    public function testIsValidALIAS()
    {
        // Valid ALIAS records - similar to CNAME format
        $this->assertTrue(Dns::is_valid_alias('target.example.com', false));
        $this->assertTrue(Dns::is_valid_alias('target.example.com.', false)); // Trailing dot
        $this->assertTrue(Dns::is_valid_alias('sub.domain.example.com', false));
        $this->assertTrue(Dns::is_valid_alias('host-name.example.com', false)); // Hyphen
        $this->assertTrue(Dns::is_valid_alias('host_name.example.com', false)); // Underscore
        $this->assertTrue(Dns::is_valid_alias('example.com', false));

        // Valid with extra spaces
        $this->assertTrue(Dns::is_valid_alias('  target.example.com  ', false));

        // Invalid: empty target
        $this->assertFalse(Dns::is_valid_alias('', false));
        $this->assertFalse(Dns::is_valid_alias('   ', false));

        // Invalid: invalid characters
        $this->assertFalse(Dns::is_valid_alias('target@example.com', false)); // @ not allowed
        $this->assertFalse(Dns::is_valid_alias('target example.com', false)); // Space not allowed
        $this->assertFalse(Dns::is_valid_alias('target/example.com', false)); // Slash not allowed
        $this->assertFalse(Dns::is_valid_alias('target!example.com', false)); // Special char not allowed
    }

    public function testIsValidAFSDB()
    {
        // Valid AFSDB records - matching PowerDNS test cases
        $this->assertTrue(Dns::is_valid_afsdb('1 afs-server.rec.test.', false));
        $this->assertTrue(Dns::is_valid_afsdb('1 afs-server.example.com.', false));

        // Valid AFSDB records - standard cases
        $this->assertTrue(Dns::is_valid_afsdb('1 afs1.example.com', false));
        $this->assertTrue(Dns::is_valid_afsdb('2 dce-server.example.com', false)); // DCE/NCA
        $this->assertTrue(Dns::is_valid_afsdb('0 server.test.com', false)); // Min subtype
        $this->assertTrue(Dns::is_valid_afsdb('65535 server.test.com', false)); // Max subtype

        // Valid with extra spaces
        $this->assertTrue(Dns::is_valid_afsdb('  1   afs-server.example.com  ', false));

        // Invalid: missing subtype
        $this->assertFalse(Dns::is_valid_afsdb('afs-server.example.com', false));

        // Invalid: missing hostname
        $this->assertFalse(Dns::is_valid_afsdb('1', false));
        $this->assertFalse(Dns::is_valid_afsdb('1 ', false));

        // Invalid: subtype out of range
        $this->assertFalse(Dns::is_valid_afsdb('65536 server.example.com', false)); // > 65535
        $this->assertFalse(Dns::is_valid_afsdb('-1 server.example.com', false)); // < 0

        // Invalid: non-numeric subtype
        $this->assertFalse(Dns::is_valid_afsdb('one afs-server.example.com', false));
        $this->assertFalse(Dns::is_valid_afsdb('a afs-server.example.com', false));

        // Invalid: bad format
        $this->assertFalse(Dns::is_valid_afsdb('', false));
        $this->assertFalse(Dns::is_valid_afsdb('invalid', false));
    }

    public function testIsValidCAA()
    {
        // Valid CAA records - matching PowerDNS test cases
        $this->assertTrue(Dns::is_valid_caa('0 issue "example.net"', false));
        $this->assertTrue(Dns::is_valid_caa('0 issue ""', false)); // Empty value is valid (denies issuance)
        $this->assertTrue(Dns::is_valid_caa('0 issue ";"', false)); // Semicolon to disable
        $this->assertTrue(Dns::is_valid_caa('0 issue "a"', false)); // Single character
        $this->assertTrue(Dns::is_valid_caa('0 issue "aa"', false));
        $this->assertTrue(Dns::is_valid_caa('0 issue "aaaaaaa"', false));
        $this->assertTrue(Dns::is_valid_caa('0 issue "aaaaaaa.aaa"', false)); // Domain with dots

        // Valid CAA records - standard cases
        $this->assertTrue(Dns::is_valid_caa('0 issue "letsencrypt.org"', false));
        $this->assertTrue(Dns::is_valid_caa('0 issuewild "letsencrypt.org"', false));
        $this->assertTrue(Dns::is_valid_caa('0 iodef "mailto:admin@example.com"', false));
        $this->assertTrue(Dns::is_valid_caa('128 issue "ca.example.net"', false)); // Critical flag

        // Valid flags range
        $this->assertTrue(Dns::is_valid_caa('255 issue "example.com"', false));

        // Valid with any alphanumeric tag (RFC 8659 extensibility)
        $this->assertTrue(Dns::is_valid_caa('0 contactemail "admin@example.com"', false));
        $this->assertTrue(Dns::is_valid_caa('0 contactphone "+1-555-1234"', false));
        $this->assertTrue(Dns::is_valid_caa('0 customtag123 "value"', false));

        // Valid escaped quotes inside value
        $this->assertTrue(Dns::is_valid_caa('0 issue "let\\"s"', false));
        $this->assertTrue(Dns::is_valid_caa('0 issue "ca\\"example\\"org"', false));

        // Invalid: missing flags (from issue #790)
        $this->assertFalse(Dns::is_valid_caa('issuewild "letsencrypt.org"', false));

        // Invalid: missing tag
        $this->assertFalse(Dns::is_valid_caa('0 "letsencrypt.org"', false));

        // Invalid: missing value field (RFC 8659 requires value field, even if empty "")
        $this->assertFalse(Dns::is_valid_caa('0 issue', false));
        $this->assertFalse(Dns::is_valid_caa('0 issuewild', false));
        $this->assertFalse(Dns::is_valid_caa('128 iodef', false));

        // Invalid: unquoted value (PowerDNS requires quotes)
        $this->assertFalse(Dns::is_valid_caa('0 issue letsencrypt.org', false));
        $this->assertFalse(Dns::is_valid_caa('0 issuewild letsencrypt.org', false));

        // Invalid: flags out of range
        $this->assertFalse(Dns::is_valid_caa('256 issue "letsencrypt.org"', false));
        $this->assertFalse(Dns::is_valid_caa('-1 issue "letsencrypt.org"', false));

        // Invalid: bad format
        $this->assertFalse(Dns::is_valid_caa('invalid', false));
        $this->assertFalse(Dns::is_valid_caa('', false));

        // Invalid: unescaped quotes in value
        $this->assertFalse(Dns::is_valid_caa('0 issue "lets"encrypt.org"', false));

        // Invalid: non-alphanumeric tag
        $this->assertFalse(Dns::is_valid_caa('0 issue-wild "test"', false)); // Hyphen not allowed
        $this->assertFalse(Dns::is_valid_caa('0 issue.wild "test"', false)); // Dot not allowed
    }

    public function testIsValidCDNSKEY()
    {
        // Valid CDNSKEY records - matching PowerDNS DNSKEY test case format
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 5 AwEAAZVtlHc8O4TVmlGx/PGJTc7hbVjMR7RywxLuAm1dqgyHvgNR', false));
        $this->assertTrue(Dns::is_valid_cdnskey('256 3 8 AwEAAa1234567890abcdefghijklmnopqrstuvwxyz', false));

        // Valid flags
        $this->assertTrue(Dns::is_valid_cdnskey('256 3 5 AwEAAa==', false)); // Zone Signing Key (ZSK)
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 5 AwEAAa==', false)); // Key Signing Key (KSK)
        $this->assertTrue(Dns::is_valid_cdnskey('0 3 5 AwEAAa==', false)); // Min flags
        $this->assertTrue(Dns::is_valid_cdnskey('65535 3 5 AwEAAa==', false)); // Max flags

        // Valid protocols (typically 3 for DNSSEC)
        $this->assertTrue(Dns::is_valid_cdnskey('257 0 5 AwEAAa==', false)); // Min protocol
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 5 AwEAAa==', false)); // Standard DNSSEC protocol
        $this->assertTrue(Dns::is_valid_cdnskey('257 255 5 AwEAAa==', false)); // Max protocol

        // Valid algorithms
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 0 AwEAAa==', false)); // Min algorithm
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 5 AwEAAa==', false)); // RSASHA1
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 8 AwEAAa==', false)); // RSASHA256
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 13 AwEAAa==', false)); // ECDSAP256SHA256
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 255 AwEAAa==', false)); // Max algorithm

        // Valid Base64 public keys with various characters
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 5 AwEAAa+/0123456789', false));
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 5 AAAA', false)); // 4 characters (minimal valid)
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 5 AA==', false)); // With double padding
        $this->assertTrue(Dns::is_valid_cdnskey('257 3 5 AAA=', false)); // With single padding

        // Valid with extra spaces
        $this->assertTrue(Dns::is_valid_cdnskey('  257   3   5   AwEAAa==  ', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_cdnskey('257 3 5', false)); // Missing public key
        $this->assertFalse(Dns::is_valid_cdnskey('257 3', false)); // Missing algorithm and key
        $this->assertFalse(Dns::is_valid_cdnskey('257', false)); // Missing protocol, algorithm, and key
        $this->assertFalse(Dns::is_valid_cdnskey('', false)); // Empty

        // Invalid: flags out of range
        $this->assertFalse(Dns::is_valid_cdnskey('65536 3 5 AwEAAa==', false)); // > 65535
        $this->assertFalse(Dns::is_valid_cdnskey('-1 3 5 AwEAAa==', false)); // < 0

        // Invalid: protocol out of range
        $this->assertFalse(Dns::is_valid_cdnskey('257 256 5 AwEAAa==', false)); // > 255
        $this->assertFalse(Dns::is_valid_cdnskey('257 -1 5 AwEAAa==', false)); // < 0

        // Invalid: algorithm out of range
        $this->assertFalse(Dns::is_valid_cdnskey('257 3 256 AwEAAa==', false)); // > 255
        $this->assertFalse(Dns::is_valid_cdnskey('257 3 -1 AwEAAa==', false)); // < 0

        // Invalid: non-numeric values
        $this->assertFalse(Dns::is_valid_cdnskey('abc 3 5 AwEAAa==', false)); // Non-numeric flags
        $this->assertFalse(Dns::is_valid_cdnskey('257 xyz 5 AwEAAa==', false)); // Non-numeric protocol
        $this->assertFalse(Dns::is_valid_cdnskey('257 3 abc AwEAAa==', false)); // Non-numeric algorithm

        // Invalid: empty public key
        $this->assertFalse(Dns::is_valid_cdnskey('257 3 5 ', false));
        $this->assertFalse(Dns::is_valid_cdnskey('257 3 5  ', false)); // Just spaces

        // Invalid: invalid Base64 characters
        $this->assertFalse(Dns::is_valid_cdnskey('257 3 5 AwEAAa@#$', false)); // Special chars
        $this->assertFalse(Dns::is_valid_cdnskey('257 3 5 AwEAAa!', false)); // Exclamation mark
        $this->assertFalse(Dns::is_valid_cdnskey('257 3 5 AwEAAa~', false)); // Tilde

        // Invalid: malformed Base64
        $this->assertFalse(Dns::is_valid_cdnskey('257 3 5 ===', false)); // Only padding
        $this->assertFalse(Dns::is_valid_cdnskey('257 3 5 A===', false)); // Too much padding

        // Invalid: bad format
        $this->assertFalse(Dns::is_valid_cdnskey('invalid', false));
        $this->assertFalse(Dns::is_valid_cdnskey('257-3-5-AwEAAa==', false)); // Wrong separator
    }

    public function testIsValidCDS()
    {
        // Valid CDS records - matching PowerDNS DS test case
        $this->assertTrue(Dns::is_valid_cds('20642 8 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false));
        $this->assertTrue(Dns::is_valid_cds('20642 8 2 04443abe7e94c3985196beae5d548c727b044dda5151e60d7cd76a9fd931d00e', false)); // Lowercase

        // Valid keytag values
        $this->assertTrue(Dns::is_valid_cds('0 8 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // Min keytag
        $this->assertTrue(Dns::is_valid_cds('65535 8 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // Max keytag
        $this->assertTrue(Dns::is_valid_cds('12345 8 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false));

        // Valid algorithm values
        $this->assertTrue(Dns::is_valid_cds('20642 0 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // Min algorithm
        $this->assertTrue(Dns::is_valid_cds('20642 5 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // RSASHA1
        $this->assertTrue(Dns::is_valid_cds('20642 8 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // RSASHA256
        $this->assertTrue(Dns::is_valid_cds('20642 13 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // ECDSAP256SHA256
        $this->assertTrue(Dns::is_valid_cds('20642 255 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // Max algorithm

        // Valid digest type values
        $this->assertTrue(Dns::is_valid_cds('20642 8 0 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // Min digesttype
        $this->assertTrue(Dns::is_valid_cds('20642 8 1 9C79EA1B56DCFC6B17A407809E1B82E4259EDBB5', false)); // SHA-1 (40 hex chars)
        $this->assertTrue(Dns::is_valid_cds('20642 8 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // SHA-256 (64 hex chars)
        $this->assertTrue(Dns::is_valid_cds('20642 8 4 1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF', false)); // SHA-384 (96 hex chars)
        $this->assertTrue(Dns::is_valid_cds('20642 8 255 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // Max digesttype

        // Valid with extra spaces
        $this->assertTrue(Dns::is_valid_cds('  20642   8   2   04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E  ', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_cds('20642 8 2', false)); // Missing digest
        $this->assertFalse(Dns::is_valid_cds('20642 8', false)); // Missing digesttype and digest
        $this->assertFalse(Dns::is_valid_cds('20642', false)); // Missing algorithm, digesttype, and digest
        $this->assertFalse(Dns::is_valid_cds('', false)); // Empty

        // Invalid: keytag out of range
        $this->assertFalse(Dns::is_valid_cds('65536 8 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // > 65535
        $this->assertFalse(Dns::is_valid_cds('-1 8 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // < 0

        // Invalid: algorithm out of range
        $this->assertFalse(Dns::is_valid_cds('20642 256 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // > 255
        $this->assertFalse(Dns::is_valid_cds('20642 -1 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // < 0

        // Invalid: digesttype out of range
        $this->assertFalse(Dns::is_valid_cds('20642 8 256 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // > 255
        $this->assertFalse(Dns::is_valid_cds('20642 8 -1 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // < 0

        // Invalid: non-numeric values
        $this->assertFalse(Dns::is_valid_cds('abc 8 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // Non-numeric keytag
        $this->assertFalse(Dns::is_valid_cds('20642 xyz 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // Non-numeric algorithm
        $this->assertFalse(Dns::is_valid_cds('20642 8 abc 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // Non-numeric digesttype

        // Invalid: empty digest
        $this->assertFalse(Dns::is_valid_cds('20642 8 2 ', false));
        $this->assertFalse(Dns::is_valid_cds('20642 8 2  ', false)); // Just spaces

        // Invalid: non-hexadecimal digest
        $this->assertFalse(Dns::is_valid_cds('20642 8 2 GHIJKLMN7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // G, H not hex
        $this->assertFalse(Dns::is_valid_cds('20642 8 2 04443ABE-7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // Dash not allowed

        // Invalid: wrong digest length
        $this->assertFalse(Dns::is_valid_cds('20642 8 2 04443ABE', false)); // Too short (8 chars)
        $this->assertFalse(Dns::is_valid_cds('20642 8 2 04443ABE7E94C3985196BEAE5D548C72', false)); // 32 chars (not standard)
        $this->assertFalse(Dns::is_valid_cds('20642 8 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00EABC', false)); // 68 chars (too long)

        // Invalid: bad format
        $this->assertFalse(Dns::is_valid_cds('invalid', false));
        $this->assertFalse(Dns::is_valid_cds('20642-8-2-04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E', false)); // Wrong separator
    }

    public function testIsValidCERT()
    {
        // Valid CERT records - matching PowerDNS test case (truncated for readability)
        $this->assertTrue(Dns::is_valid_cert('1 0 0 MIIB9DCCAV2gAwIBAgIJAKxUfFVXhw7HMA0GCSqGSIb3DQEBBQUAMBMxETAPBgNVBAMMCHJlYy50ZXN0', false));

        // Valid certificate types (RFC 4398)
        $this->assertTrue(Dns::is_valid_cert('1 0 0 AAAA', false)); // PKIX (X.509)
        $this->assertTrue(Dns::is_valid_cert('2 0 0 AAAA', false)); // SPKI
        $this->assertTrue(Dns::is_valid_cert('3 0 0 AAAA', false)); // PGP
        $this->assertTrue(Dns::is_valid_cert('4 0 0 AAAA', false)); // IPKIX
        $this->assertTrue(Dns::is_valid_cert('5 0 0 AAAA', false)); // ISPKI
        $this->assertTrue(Dns::is_valid_cert('6 0 0 AAAA', false)); // IPGP
        $this->assertTrue(Dns::is_valid_cert('7 0 0 AAAA', false)); // ACPKIX
        $this->assertTrue(Dns::is_valid_cert('8 0 0 AAAA', false)); // IACPKIX
        $this->assertTrue(Dns::is_valid_cert('253 0 0 AAAA', false)); // URI private
        $this->assertTrue(Dns::is_valid_cert('254 0 0 AAAA', false)); // OID private

        // Valid type range
        $this->assertTrue(Dns::is_valid_cert('0 0 0 AAAA', false)); // Min type
        $this->assertTrue(Dns::is_valid_cert('65535 0 0 AAAA', false)); // Max type

        // Valid keytag range
        $this->assertTrue(Dns::is_valid_cert('1 0 0 AAAA', false)); // Min keytag
        $this->assertTrue(Dns::is_valid_cert('1 65535 0 AAAA', false)); // Max keytag
        $this->assertTrue(Dns::is_valid_cert('1 12345 0 AAAA', false));

        // Valid algorithm range
        $this->assertTrue(Dns::is_valid_cert('1 0 0 AAAA', false)); // Min algorithm
        $this->assertTrue(Dns::is_valid_cert('1 0 5 AAAA', false)); // RSASHA1
        $this->assertTrue(Dns::is_valid_cert('1 0 8 AAAA', false)); // RSASHA256
        $this->assertTrue(Dns::is_valid_cert('1 0 255 AAAA', false)); // Max algorithm

        // Valid Base64 certificates
        $this->assertTrue(Dns::is_valid_cert('1 0 0 AAAA', false)); // 4 characters (minimal valid)
        $this->assertTrue(Dns::is_valid_cert('1 0 0 AA==', false)); // With double padding
        $this->assertTrue(Dns::is_valid_cert('1 0 0 AAA=', false)); // With single padding
        $this->assertTrue(Dns::is_valid_cert('1 0 0 AwEAAa+/0123456789', false)); // With +/

        // Valid with extra spaces
        $this->assertTrue(Dns::is_valid_cert('  1   0   0   AAAA  ', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_cert('1 0 0', false)); // Missing certificate
        $this->assertFalse(Dns::is_valid_cert('1 0', false)); // Missing algorithm and certificate
        $this->assertFalse(Dns::is_valid_cert('1', false)); // Missing keytag, algorithm, and certificate
        $this->assertFalse(Dns::is_valid_cert('', false)); // Empty

        // Invalid: type out of range
        $this->assertFalse(Dns::is_valid_cert('65536 0 0 AAAA', false)); // > 65535
        $this->assertFalse(Dns::is_valid_cert('-1 0 0 AAAA', false)); // < 0

        // Invalid: keytag out of range
        $this->assertFalse(Dns::is_valid_cert('1 65536 0 AAAA', false)); // > 65535
        $this->assertFalse(Dns::is_valid_cert('1 -1 0 AAAA', false)); // < 0

        // Invalid: algorithm out of range
        $this->assertFalse(Dns::is_valid_cert('1 0 256 AAAA', false)); // > 255
        $this->assertFalse(Dns::is_valid_cert('1 0 -1 AAAA', false)); // < 0

        // Invalid: non-numeric values
        $this->assertFalse(Dns::is_valid_cert('abc 0 0 AAAA', false)); // Non-numeric type
        $this->assertFalse(Dns::is_valid_cert('1 xyz 0 AAAA', false)); // Non-numeric keytag
        $this->assertFalse(Dns::is_valid_cert('1 0 abc AAAA', false)); // Non-numeric algorithm

        // Invalid: empty certificate
        $this->assertFalse(Dns::is_valid_cert('1 0 0 ', false));
        $this->assertFalse(Dns::is_valid_cert('1 0 0  ', false)); // Just spaces

        // Invalid: invalid Base64 characters
        $this->assertFalse(Dns::is_valid_cert('1 0 0 AAAA@#$', false)); // Special chars
        $this->assertFalse(Dns::is_valid_cert('1 0 0 AAAA!', false)); // Exclamation mark
        $this->assertFalse(Dns::is_valid_cert('1 0 0 AAAA~', false)); // Tilde

        // Invalid: malformed Base64
        $this->assertFalse(Dns::is_valid_cert('1 0 0 ===', false)); // Only padding
        $this->assertFalse(Dns::is_valid_cert('1 0 0 A===', false)); // Too much padding

        // Invalid: bad format
        $this->assertFalse(Dns::is_valid_cert('invalid', false));
        $this->assertFalse(Dns::is_valid_cert('1-0-0-AAAA', false)); // Wrong separator
    }

    public function testIsValidDNAME()
    {
        // Valid DNAME records - simple hostnames
        $this->assertTrue(Dns::is_valid_dname('example.com', false));
        $this->assertTrue(Dns::is_valid_dname('example.com.', false)); // With trailing dot
        $this->assertTrue(Dns::is_valid_dname('target.example.org', false));
        $this->assertTrue(Dns::is_valid_dname('sub.domain.test.', false));

        // Valid with hyphens
        $this->assertTrue(Dns::is_valid_dname('my-example.com', false));
        $this->assertTrue(Dns::is_valid_dname('sub-domain.example.org', false));

        // Valid with underscores (allowed in DNS)
        $this->assertTrue(Dns::is_valid_dname('_service.example.com', false));
        $this->assertTrue(Dns::is_valid_dname('example_test.com', false));

        // Valid with numbers
        $this->assertTrue(Dns::is_valid_dname('example123.com', false));
        $this->assertTrue(Dns::is_valid_dname('123example.com', false));
        $this->assertTrue(Dns::is_valid_dname('test.123.example.com', false));

        // Valid single-label domains
        $this->assertTrue(Dns::is_valid_dname('localhost', false));
        $this->assertTrue(Dns::is_valid_dname('example', false));

        // Valid with multiple subdomains
        $this->assertTrue(Dns::is_valid_dname('a.b.c.d.e.example.com', false));

        // Valid with extra spaces (trimmed)
        $this->assertTrue(Dns::is_valid_dname('  example.com  ', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_dname('', false));
        $this->assertFalse(Dns::is_valid_dname('   ', false)); // Just spaces

        // Invalid: contains spaces
        $this->assertFalse(Dns::is_valid_dname('example .com', false));
        $this->assertFalse(Dns::is_valid_dname('example. com', false));
        $this->assertFalse(Dns::is_valid_dname('example com', false));

        // Invalid: invalid characters
        $this->assertFalse(Dns::is_valid_dname('example@com', false)); // @ not allowed
        $this->assertFalse(Dns::is_valid_dname('example!com', false)); // ! not allowed
        $this->assertFalse(Dns::is_valid_dname('example#com', false)); // # not allowed
        $this->assertFalse(Dns::is_valid_dname('example$com', false)); // $ not allowed
        $this->assertFalse(Dns::is_valid_dname('example%com', false)); // % not allowed
        $this->assertFalse(Dns::is_valid_dname('example&com', false)); // & not allowed
        $this->assertFalse(Dns::is_valid_dname('example*com', false)); // * not allowed
        $this->assertFalse(Dns::is_valid_dname('example(com)', false)); // () not allowed
        $this->assertFalse(Dns::is_valid_dname('example[com]', false)); // [] not allowed
        $this->assertFalse(Dns::is_valid_dname('example{com}', false)); // {} not allowed
        $this->assertFalse(Dns::is_valid_dname('example/com', false)); // / not allowed
        $this->assertFalse(Dns::is_valid_dname('example\\com', false)); // \ not allowed
        $this->assertFalse(Dns::is_valid_dname('example:com', false)); // : not allowed
        $this->assertFalse(Dns::is_valid_dname('example;com', false)); // ; not allowed
        $this->assertFalse(Dns::is_valid_dname('example,com', false)); // , not allowed
        $this->assertFalse(Dns::is_valid_dname('example<com>', false)); // <> not allowed
        $this->assertFalse(Dns::is_valid_dname('example?com', false)); // ? not allowed
        $this->assertFalse(Dns::is_valid_dname('example|com', false)); // | not allowed
        $this->assertFalse(Dns::is_valid_dname('example~com', false)); // ~ not allowed
        $this->assertFalse(Dns::is_valid_dname('example`com', false)); // ` not allowed
        $this->assertFalse(Dns::is_valid_dname('example"com', false)); // " not allowed
        $this->assertFalse(Dns::is_valid_dname("example'com", false)); // ' not allowed
    }

    public function testIsValidL32()
    {
        // Valid L32 records - matching PowerDNS test case
        $this->assertTrue(Dns::is_valid_l32('513 192.0.2.1', false));

        // Valid preference values
        $this->assertTrue(Dns::is_valid_l32('0 192.0.2.1', false)); // Min preference
        $this->assertTrue(Dns::is_valid_l32('100 192.0.2.1', false));
        $this->assertTrue(Dns::is_valid_l32('1000 192.0.2.1', false));
        $this->assertTrue(Dns::is_valid_l32('65535 192.0.2.1', false)); // Max preference

        // Valid IPv4 addresses as locator32
        $this->assertTrue(Dns::is_valid_l32('513 0.0.0.0', false)); // Min IP
        $this->assertTrue(Dns::is_valid_l32('513 255.255.255.255', false)); // Max IP
        $this->assertTrue(Dns::is_valid_l32('513 10.0.0.1', false)); // Private IP
        $this->assertTrue(Dns::is_valid_l32('513 172.16.0.1', false)); // Private IP
        $this->assertTrue(Dns::is_valid_l32('513 192.168.1.1', false)); // Private IP
        $this->assertTrue(Dns::is_valid_l32('513 127.0.0.1', false)); // Localhost
        $this->assertTrue(Dns::is_valid_l32('513 8.8.8.8', false)); // Public IP
        $this->assertTrue(Dns::is_valid_l32('513 1.2.3.4', false));

        // Valid with extra spaces
        $this->assertTrue(Dns::is_valid_l32('  513   192.0.2.1  ', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_l32('513', false)); // Missing locator32
        $this->assertFalse(Dns::is_valid_l32('513 ', false)); // Missing locator32
        $this->assertFalse(Dns::is_valid_l32('192.0.2.1', false)); // Missing preference
        $this->assertFalse(Dns::is_valid_l32('', false)); // Empty

        // Invalid: preference out of range
        $this->assertFalse(Dns::is_valid_l32('65536 192.0.2.1', false)); // > 65535
        $this->assertFalse(Dns::is_valid_l32('-1 192.0.2.1', false)); // < 0

        // Invalid: non-numeric preference
        $this->assertFalse(Dns::is_valid_l32('abc 192.0.2.1', false));
        $this->assertFalse(Dns::is_valid_l32('high 192.0.2.1', false));

        // Invalid: invalid IPv4 addresses
        $this->assertFalse(Dns::is_valid_l32('513 256.0.0.1', false)); // Octet > 255
        $this->assertFalse(Dns::is_valid_l32('513 192.0.2.256', false)); // Octet > 255
        $this->assertFalse(Dns::is_valid_l32('513 192.0.2', false)); // Missing octet
        $this->assertFalse(Dns::is_valid_l32('513 192.0.2.1.1', false)); // Too many octets
        $this->assertFalse(Dns::is_valid_l32('513 192.0.2.a', false)); // Non-numeric octet
        $this->assertFalse(Dns::is_valid_l32('513 192.0.2.-1', false)); // Negative octet
        $this->assertFalse(Dns::is_valid_l32('513 example.com', false)); // Hostname not allowed
        $this->assertFalse(Dns::is_valid_l32('513 2001:db8::1', false)); // IPv6 not allowed

        // Invalid: bad format
        $this->assertFalse(Dns::is_valid_l32('invalid', false));
        $this->assertFalse(Dns::is_valid_l32('513-192.0.2.1', false)); // Wrong separator
        $this->assertFalse(Dns::is_valid_l32('513,192.0.2.1', false)); // Wrong separator
    }

    public function testIsValidL64()
    {
        // Valid L64 records - matching PowerDNS test case
        $this->assertTrue(Dns::is_valid_l64('255 2001:0DB8:1234:ABCD', false));

        // Valid preference values
        $this->assertTrue(Dns::is_valid_l64('0 2001:0DB8:1234:ABCD', false)); // Min preference
        $this->assertTrue(Dns::is_valid_l64('100 2001:0DB8:1234:ABCD', false));
        $this->assertTrue(Dns::is_valid_l64('1000 2001:0DB8:1234:ABCD', false));
        $this->assertTrue(Dns::is_valid_l64('65535 2001:0DB8:1234:ABCD', false)); // Max preference

        // Valid locator64 formats
        $this->assertTrue(Dns::is_valid_l64('255 0000:0000:0000:0000', false)); // All zeros
        $this->assertTrue(Dns::is_valid_l64('255 FFFF:FFFF:FFFF:FFFF', false)); // All ones
        $this->assertTrue(Dns::is_valid_l64('255 2001:db8:1234:abcd', false)); // Lowercase
        $this->assertTrue(Dns::is_valid_l64('255 2001:DB8:1234:ABCD', false)); // Uppercase
        $this->assertTrue(Dns::is_valid_l64('255 2001:Db8:1234:AbCd', false)); // Mixed case

        // Valid with shortened hex groups (1-4 digits allowed)
        $this->assertTrue(Dns::is_valid_l64('255 1:2:3:4', false)); // Single digit
        $this->assertTrue(Dns::is_valid_l64('255 12:34:56:78', false)); // Two digits
        $this->assertTrue(Dns::is_valid_l64('255 123:456:789:ABC', false)); // Three digits
        $this->assertTrue(Dns::is_valid_l64('255 1234:5678:90AB:CDEF', false)); // Four digits
        $this->assertTrue(Dns::is_valid_l64('255 1:23:456:789A', false)); // Mixed lengths

        // Valid with extra spaces
        $this->assertTrue(Dns::is_valid_l64('  255   2001:0DB8:1234:ABCD  ', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_l64('255', false)); // Missing locator64
        $this->assertFalse(Dns::is_valid_l64('255 ', false)); // Missing locator64
        $this->assertFalse(Dns::is_valid_l64('2001:0DB8:1234:ABCD', false)); // Missing preference
        $this->assertFalse(Dns::is_valid_l64('', false)); // Empty

        // Invalid: preference out of range
        $this->assertFalse(Dns::is_valid_l64('65536 2001:0DB8:1234:ABCD', false)); // > 65535
        $this->assertFalse(Dns::is_valid_l64('-1 2001:0DB8:1234:ABCD', false)); // < 0

        // Invalid: non-numeric preference
        $this->assertFalse(Dns::is_valid_l64('abc 2001:0DB8:1234:ABCD', false));
        $this->assertFalse(Dns::is_valid_l64('high 2001:0DB8:1234:ABCD', false));

        // Invalid: wrong number of groups
        $this->assertFalse(Dns::is_valid_l64('255 2001:0DB8:1234', false)); // Only 3 groups
        $this->assertFalse(Dns::is_valid_l64('255 2001:0DB8', false)); // Only 2 groups
        $this->assertFalse(Dns::is_valid_l64('255 2001', false)); // Only 1 group
        $this->assertFalse(Dns::is_valid_l64('255 2001:0DB8:1234:ABCD:EFEF', false)); // 5 groups (too many)

        // Invalid: invalid hex characters
        $this->assertFalse(Dns::is_valid_l64('255 GGGG:0DB8:1234:ABCD', false)); // G not hex
        $this->assertFalse(Dns::is_valid_l64('255 2001:ZZZZ:1234:ABCD', false)); // Z not hex
        $this->assertFalse(Dns::is_valid_l64('255 2001:0DB8:XXXX:ABCD', false)); // X not hex

        // Invalid: too many digits in a group
        $this->assertFalse(Dns::is_valid_l64('255 12345:0DB8:1234:ABCD', false)); // 5 digits
        $this->assertFalse(Dns::is_valid_l64('255 2001:0DB8:123456:ABCD', false)); // 6 digits

        // Invalid: wrong separator
        $this->assertFalse(Dns::is_valid_l64('255 2001.0DB8.1234.ABCD', false)); // Dots instead of colons
        $this->assertFalse(Dns::is_valid_l64('255 2001-0DB8-1234-ABCD', false)); // Dashes instead of colons

        // Invalid: IPv6 full address (not a 64-bit locator)
        $this->assertFalse(Dns::is_valid_l64('255 2001:0DB8:0000:0000:0000:0000:1234:ABCD', false)); // 128-bit IPv6

        // Invalid: IPv4 address
        $this->assertFalse(Dns::is_valid_l64('255 192.0.2.1', false));

        // Invalid: bad format
        $this->assertFalse(Dns::is_valid_l64('invalid', false));
        $this->assertFalse(Dns::is_valid_l64('255-2001:0DB8:1234:ABCD', false)); // Wrong separator between preference and locator
    }

    public function testIsValidLUA()
    {
        // Valid LUA records - common PowerDNS patterns
        $this->assertTrue(Dns::is_valid_lua('A "ifportup(443, {\'192.0.2.1\', \'192.0.2.2\'})"', false));
        $this->assertTrue(Dns::is_valid_lua('AAAA "ifurlup(\'https://example.com/\', {{\'2001:db8::1\', \'2001:db8::2\'}})"', false));
        $this->assertTrue(Dns::is_valid_lua('TXT "Hello from Lua"', false));

        // Valid with different record types
        $this->assertTrue(Dns::is_valid_lua('A "return 192.0.2.1"', false));
        $this->assertTrue(Dns::is_valid_lua('AAAA "return \'2001:db8::1\'"', false));
        $this->assertTrue(Dns::is_valid_lua('CNAME "return \'example.com\'"', false));
        $this->assertTrue(Dns::is_valid_lua('MX "return 10, \'mail.example.com\'"', false));
        $this->assertTrue(Dns::is_valid_lua('TXT "return \'v=spf1 ~all\'"', false));
        $this->assertTrue(Dns::is_valid_lua('NS "return \'ns1.example.com\'"', false));
        $this->assertTrue(Dns::is_valid_lua('PTR "return \'example.com\'"', false));
        $this->assertTrue(Dns::is_valid_lua('SRV "return 10, 20, 80, \'server.example.com\'"', false));

        // Valid with complex Lua code
        $this->assertTrue(Dns::is_valid_lua('A "if country == \'US\' then return \'192.0.2.1\' else return \'192.0.2.2\' end"', false));
        $this->assertTrue(Dns::is_valid_lua('AAAA "local ips = {\'2001:db8::1\', \'2001:db8::2\'}; return ips[math.random(#ips)]"', false));

        // Valid with simple function calls
        $this->assertTrue(Dns::is_valid_lua('A "ifportup(80, {\'192.0.2.1\'})"', false));
        $this->assertTrue(Dns::is_valid_lua('A "pickrandom({\'192.0.2.1\', \'192.0.2.2\'})"', false));
        $this->assertTrue(Dns::is_valid_lua('A "pickweightedrandom({{10, \'192.0.2.1\'}, {20, \'192.0.2.2\'}})"', false));

        // Valid with extra spaces
        $this->assertTrue(Dns::is_valid_lua('  A   "return 192.0.2.1"  ', false));

        // Valid case-insensitive type
        $this->assertTrue(Dns::is_valid_lua('a "return 192.0.2.1"', false));
        $this->assertTrue(Dns::is_valid_lua('AaAa "return 2001:db8::1"', false));
        $this->assertTrue(Dns::is_valid_lua('txt "return text"', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_lua('', false));
        $this->assertFalse(Dns::is_valid_lua('   ', false));

        // Invalid: missing type
        $this->assertFalse(Dns::is_valid_lua('"return 192.0.2.1"', false));
        $this->assertFalse(Dns::is_valid_lua('return 192.0.2.1', false));

        // Invalid: missing lua code
        $this->assertFalse(Dns::is_valid_lua('A', false));
        $this->assertFalse(Dns::is_valid_lua('A ', false));
        $this->assertFalse(Dns::is_valid_lua('AAAA   ', false));

        // Invalid: invalid DNS record type
        $this->assertFalse(Dns::is_valid_lua('INVALID "return something"', false));
        $this->assertFalse(Dns::is_valid_lua('FOO "return bar"', false));
        $this->assertFalse(Dns::is_valid_lua('NOTADNSTYPE "return data"', false));
        $this->assertFalse(Dns::is_valid_lua('XYZ "code here"', false));

        // Invalid: bad format
        $this->assertFalse(Dns::is_valid_lua('A-"return 192.0.2.1"', false)); // No space
        $this->assertFalse(Dns::is_valid_lua('A:"return 192.0.2.1"', false)); // Wrong separator
    }

    public function testIsValidLP()
    {
        // Valid LP records - matching PowerDNS test case
        $this->assertTrue(Dns::is_valid_lp('512 foo.powerdns.org.', false));

        // Valid preference values
        $this->assertTrue(Dns::is_valid_lp('0 example.com', false)); // Min preference
        $this->assertTrue(Dns::is_valid_lp('100 example.com', false));
        $this->assertTrue(Dns::is_valid_lp('1000 example.com', false));
        $this->assertTrue(Dns::is_valid_lp('65535 example.com', false)); // Max preference

        // Valid FQDNs
        $this->assertTrue(Dns::is_valid_lp('512 example.com', false));
        $this->assertTrue(Dns::is_valid_lp('512 example.com.', false)); // With trailing dot
        $this->assertTrue(Dns::is_valid_lp('512 sub.example.com', false));
        $this->assertTrue(Dns::is_valid_lp('512 deep.sub.example.com.', false));
        $this->assertTrue(Dns::is_valid_lp('512 a.b.c.d.e.example.com', false));

        // Valid with hyphens
        $this->assertTrue(Dns::is_valid_lp('512 my-server.example.com', false));
        $this->assertTrue(Dns::is_valid_lp('512 server-1.example.com', false));

        // Valid with underscores
        $this->assertTrue(Dns::is_valid_lp('512 _service.example.com', false));
        $this->assertTrue(Dns::is_valid_lp('512 server_1.example.com', false));

        // Valid with numbers
        $this->assertTrue(Dns::is_valid_lp('512 server123.example.com', false));
        $this->assertTrue(Dns::is_valid_lp('512 123.example.com', false));

        // Valid single-label domains
        $this->assertTrue(Dns::is_valid_lp('512 localhost', false));
        $this->assertTrue(Dns::is_valid_lp('512 example', false));

        // Valid with extra spaces
        $this->assertTrue(Dns::is_valid_lp('  512   example.com  ', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_lp('512', false)); // Missing FQDN
        $this->assertFalse(Dns::is_valid_lp('512 ', false)); // Missing FQDN
        $this->assertFalse(Dns::is_valid_lp('example.com', false)); // Missing preference
        $this->assertFalse(Dns::is_valid_lp('', false)); // Empty

        // Invalid: preference out of range
        $this->assertFalse(Dns::is_valid_lp('65536 example.com', false)); // > 65535
        $this->assertFalse(Dns::is_valid_lp('-1 example.com', false)); // < 0

        // Invalid: non-numeric preference
        $this->assertFalse(Dns::is_valid_lp('abc example.com', false));
        $this->assertFalse(Dns::is_valid_lp('high example.com', false));

        // Invalid: FQDN with spaces
        $this->assertFalse(Dns::is_valid_lp('512 example .com', false));
        $this->assertFalse(Dns::is_valid_lp('512 example. com', false));
        $this->assertFalse(Dns::is_valid_lp('512 example com', false));

        // Invalid: FQDN with invalid characters
        $this->assertFalse(Dns::is_valid_lp('512 example@com', false)); // @ not allowed
        $this->assertFalse(Dns::is_valid_lp('512 example!com', false)); // ! not allowed
        $this->assertFalse(Dns::is_valid_lp('512 example#com', false)); // # not allowed
        $this->assertFalse(Dns::is_valid_lp('512 example$com', false)); // $ not allowed
        $this->assertFalse(Dns::is_valid_lp('512 example%com', false)); // % not allowed
        $this->assertFalse(Dns::is_valid_lp('512 example&com', false)); // & not allowed
        $this->assertFalse(Dns::is_valid_lp('512 example*com', false)); // * not allowed
        $this->assertFalse(Dns::is_valid_lp('512 example/com', false)); // / not allowed
        $this->assertFalse(Dns::is_valid_lp('512 example:com', false)); // : not allowed

        // Invalid: bad format
        $this->assertFalse(Dns::is_valid_lp('invalid', false));
        $this->assertFalse(Dns::is_valid_lp('512-example.com', false)); // Wrong separator
        $this->assertFalse(Dns::is_valid_lp('512,example.com', false)); // Wrong separator
    }

    public function testIsValidMetaQueryType()
    {
        // MAILA and MAILB are meta-query types and should always be rejected
        // They are used only in DNS queries, not in zone files

        // Invalid: MAILA is a meta-query type (obsolete)
        $this->assertFalse(Dns::is_valid_meta_query_type('MAILA', false));
        $this->assertFalse(Dns::is_valid_meta_query_type('maila', false));

        // Invalid: MAILB is a meta-query type (obsolete)
        $this->assertFalse(Dns::is_valid_meta_query_type('MAILB', false));
        $this->assertFalse(Dns::is_valid_meta_query_type('mailb', false));

        // Invalid: Other meta-query types for reference
        $this->assertFalse(Dns::is_valid_meta_query_type('ANY', false));
        $this->assertFalse(Dns::is_valid_meta_query_type('AXFR', false));
    }

    public function testIsValidOPENPGPKEY()
    {
        // Valid OPENPGPKEY records - Base64-encoded PGP keys
        // Using a truncated version of the PowerDNS test case for readability
        $this->assertTrue(Dns::is_valid_openpgpkey('mQINBFUIXh0BEADNPlL6NpWEaR2KJx6p19scIVpsBIo7UqzCIzeFbRJa', false));

        // Valid with various Base64 characters
        $this->assertTrue(Dns::is_valid_openpgpkey('AAAA', false)); // Minimal valid
        $this->assertTrue(Dns::is_valid_openpgpkey('AA==', false)); // With padding
        $this->assertTrue(Dns::is_valid_openpgpkey('AAA=', false)); // With single padding
        $this->assertTrue(Dns::is_valid_openpgpkey('AwEAAa+/0123456789', false)); // With +/

        // Valid long Base64 string (typical PGP key)
        $validPgpKey = 'mQINBFUIXh0BEADNPlL6NpWEaR2KJx6p19scIVpsBIo7UqzCIzeFbRJaGDhn/HlQgcwAalcVNmWUX0ZQsrdn9CEfLWuFu9ON2o1TslYiwn+oSAlH2raFm2eyJTp/iM7IUUCte5jmf3d+L9rjVI7JjmMnbVo6SVY2KDDD72dULcg7IqYcCAN4CT+tPZP5y4cYf+DxRlpxhxvqqiGyAi6lAcJ24/8fJ4hsG0lS1vU12LWeWTHa5aRM';
        $this->assertTrue(Dns::is_valid_openpgpkey($validPgpKey, false));

        // Valid with extra spaces (trimmed)
        $this->assertTrue(Dns::is_valid_openpgpkey('  AAAA  ', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_openpgpkey('', false));
        $this->assertFalse(Dns::is_valid_openpgpkey('   ', false)); // Just spaces

        // Invalid: invalid Base64 characters
        $this->assertFalse(Dns::is_valid_openpgpkey('AAAA@#$', false)); // Special chars
        $this->assertFalse(Dns::is_valid_openpgpkey('AAAA!', false)); // Exclamation mark
        $this->assertFalse(Dns::is_valid_openpgpkey('AAAA~', false)); // Tilde
        $this->assertFalse(Dns::is_valid_openpgpkey('AAAA%', false)); // Percent
        $this->assertFalse(Dns::is_valid_openpgpkey('AAAA&', false)); // Ampersand
        $this->assertFalse(Dns::is_valid_openpgpkey('AAAA*', false)); // Asterisk
        $this->assertFalse(Dns::is_valid_openpgpkey('AAAA()', false)); // Parentheses

        // Invalid: malformed Base64
        $this->assertFalse(Dns::is_valid_openpgpkey('===', false)); // Only padding
        $this->assertFalse(Dns::is_valid_openpgpkey('A===', false)); // Too much padding

        // Invalid: contains spaces in the middle
        $this->assertFalse(Dns::is_valid_openpgpkey('AAAA BBBB', false));
        $this->assertFalse(Dns::is_valid_openpgpkey('mQINBF UIXh0', false));

        // Invalid: contains newlines
        $this->assertFalse(Dns::is_valid_openpgpkey("AAAA\nBBBB", false));
        $this->assertFalse(Dns::is_valid_openpgpkey("mQINBF\nUIXh0", false));
    }

    public function testIsValidSIG()
    {
        // Valid SIG records - matching PowerDNS RRSIG test case format
        $this->assertTrue(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 rec.test. ecWKD/OsdAiXpbM/sgPT82KVD/WiQnnqcxoJgiH3ixHa+LOAcYU7FG7V4BRRJxLriY1e0rB2gAs3kCel9D4bzfK6wAqG4Di/eHUgHptRlaR2ycELJ4t1pjzrnuGiIzA1wM2izRmeE+Xoy1367Qu0pOz5DLzTfQITWFsB2iUzN4Y=', false));

        // Valid with different record types
        $this->assertTrue(Dns::is_valid_sig('A 8 3 300 20130523000000 20130509000000 54216 example.com. AAAA', false));
        $this->assertTrue(Dns::is_valid_sig('AAAA 8 3 300 20130523000000 20130509000000 54216 example.com. AAAA', false));
        $this->assertTrue(Dns::is_valid_sig('MX 8 3 300 20130523000000 20130509000000 54216 example.com. AAAA', false));
        $this->assertTrue(Dns::is_valid_sig('NS 8 3 300 20130523000000 20130509000000 54216 example.com. AAAA', false));

        // Valid algorithm values
        $this->assertTrue(Dns::is_valid_sig('SOA 0 3 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // Min algorithm
        $this->assertTrue(Dns::is_valid_sig('SOA 5 3 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // RSASHA1
        $this->assertTrue(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // RSASHA256
        $this->assertTrue(Dns::is_valid_sig('SOA 255 3 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // Max algorithm

        // Valid labels values
        $this->assertTrue(Dns::is_valid_sig('SOA 8 0 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // Min labels
        $this->assertTrue(Dns::is_valid_sig('SOA 8 255 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // Max labels

        // Valid original TTL values
        $this->assertTrue(Dns::is_valid_sig('SOA 8 3 0 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // Min TTL
        $this->assertTrue(Dns::is_valid_sig('SOA 8 3 86400 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // 1 day
        $this->assertTrue(Dns::is_valid_sig('SOA 8 3 604800 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // 1 week

        // Valid key tag values
        $this->assertTrue(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 0 rec.test. AAAA', false)); // Min key tag
        $this->assertTrue(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 65535 rec.test. AAAA', false)); // Max key tag

        // Valid signer names
        $this->assertTrue(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 example.com AAAA', false));
        $this->assertTrue(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 example.com. AAAA', false)); // With trailing dot
        $this->assertTrue(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 sub.example.com AAAA', false));

        // Valid with extra spaces
        $this->assertTrue(Dns::is_valid_sig('  SOA   8   3   300   20130523000000   20130509000000   54216   rec.test.   AAAA  ', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 rec.test.', false)); // Missing signature
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216', false)); // Missing signer and signature
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300', false)); // Missing most fields
        $this->assertFalse(Dns::is_valid_sig('', false)); // Empty

        // Invalid: algorithm out of range
        $this->assertFalse(Dns::is_valid_sig('SOA 256 3 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // > 255
        $this->assertFalse(Dns::is_valid_sig('SOA -1 3 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // < 0

        // Invalid: labels out of range
        $this->assertFalse(Dns::is_valid_sig('SOA 8 256 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // > 255
        $this->assertFalse(Dns::is_valid_sig('SOA 8 -1 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // < 0

        // Invalid: key tag out of range
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 65536 rec.test. AAAA', false)); // > 65535
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 -1 rec.test. AAAA', false)); // < 0

        // Invalid: non-numeric values
        $this->assertFalse(Dns::is_valid_sig('SOA abc 3 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // Non-numeric algorithm
        $this->assertFalse(Dns::is_valid_sig('SOA 8 abc 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // Non-numeric labels
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 abc 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // Non-numeric TTL
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 abc rec.test. AAAA', false)); // Non-numeric key tag

        // Invalid: invalid type covered
        $this->assertFalse(Dns::is_valid_sig('INVALID! 8 3 300 20130523000000 20130509000000 54216 rec.test. AAAA', false)); // Special chars

        // Invalid: empty signer name
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216  AAAA', false)); // Empty signer

        // Invalid: invalid signer name
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 rec@test AAAA', false)); // @ not allowed
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 rec!test AAAA', false)); // ! not allowed

        // Invalid: empty signature
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 rec.test. ', false));

        // Invalid: invalid Base64 signature
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 rec.test. AAAA@#$', false)); // Special chars
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 rec.test. AAAA!', false)); // Exclamation
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 rec.test. ===', false)); // Only padding
        $this->assertFalse(Dns::is_valid_sig('SOA 8 3 300 20130523000000 20130509000000 54216 rec.test. A===', false)); // Too much padding
    }

    public function testIsValidDHCID()
    {
        // Valid DHCID records
        // Based on RFC 4701 - minimum 35 bytes after decoding (2 bytes identifier type + 1 byte digest type + 32 bytes SHA-256)

        // Example from RFC 4701 (SHA-256 based)
        $this->assertTrue(Dns::is_valid_dhcid('AAIBY2/AuCccgoJbsaxcQc9TUapptP69lOjxfNuVAA2kjEA=', false));

        // Valid: 35 bytes minimum (exactly 35 bytes)
        $validMin = base64_encode(str_repeat("\x00", 35));
        $this->assertTrue(Dns::is_valid_dhcid($validMin, false));

        // Valid: 40 bytes (2 + 1 + 37)
        $valid40 = base64_encode(str_repeat("\x00", 40));
        $this->assertTrue(Dns::is_valid_dhcid($valid40, false));

        // Valid: 50 bytes
        $valid50 = base64_encode(str_repeat("\x00", 50));
        $this->assertTrue(Dns::is_valid_dhcid($valid50, false));

        // Valid: typical SHA-256 based DHCID (51 bytes: 2 + 1 + 48 for longer digest)
        $valid51 = base64_encode(str_repeat("\x00", 51));
        $this->assertTrue(Dns::is_valid_dhcid($valid51, false));

        // Valid: with whitespace (should be trimmed)
        $this->assertTrue(Dns::is_valid_dhcid('  AAIBY2/AuCccgoJbsaxcQc9TUapptP69lOjxfNuVAA2kjEA=  ', false));
        $this->assertTrue(Dns::is_valid_dhcid("\tAAIBY2/AuCccgoJbsaxcQc9TUapptP69lOjxfNuVAA2kjEA=\t", false));

        // Valid: different Base64 characters (includes all valid Base64 chars)
        $this->assertTrue(Dns::is_valid_dhcid('QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVphYmNkZWZnaGlqa2xtbm9wcXJzdHV2d3h5ejAxMjM0NTY3ODkrLw==', false));
        $this->assertTrue(Dns::is_valid_dhcid('AAIBAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', false));
        $this->assertTrue(Dns::is_valid_dhcid('AAIB////////////////////////////////////////////////', false));

        // Invalid: empty content
        $this->assertFalse(Dns::is_valid_dhcid('', false));
        $this->assertFalse(Dns::is_valid_dhcid('   ', false));
        $this->assertFalse(Dns::is_valid_dhcid("\t", false));

        // Invalid: not Base64
        $this->assertFalse(Dns::is_valid_dhcid('not-base64!', false));
        $this->assertFalse(Dns::is_valid_dhcid('invalid@chars', false));
        $this->assertFalse(Dns::is_valid_dhcid('hello world', false)); // spaces not valid in Base64
        $this->assertFalse(Dns::is_valid_dhcid('AAAA@#$%', false)); // special chars

        // Invalid: too short (less than 35 bytes after decoding)
        $invalid10 = base64_encode(str_repeat("\x00", 10));
        $this->assertFalse(Dns::is_valid_dhcid($invalid10, false));

        $invalid20 = base64_encode(str_repeat("\x00", 20));
        $this->assertFalse(Dns::is_valid_dhcid($invalid20, false));

        $invalid34 = base64_encode(str_repeat("\x00", 34)); // Just 1 byte short
        $this->assertFalse(Dns::is_valid_dhcid($invalid34, false));

        // Invalid: single character (not valid Base64)
        $this->assertFalse(Dns::is_valid_dhcid('A', false));

        // Invalid: only padding
        $this->assertFalse(Dns::is_valid_dhcid('====', false));

        // Invalid: malformed Base64
        $this->assertFalse(Dns::is_valid_dhcid('AAAA===', false)); // Too much padding
        $this->assertFalse(Dns::is_valid_dhcid('A===', false)); // Invalid padding

        // Note: PHP's base64_decode() accepts whitespace/newlines in Base64 strings
        // as they are valid for formatting purposes in RFC 4648
    }

    public function testIsValidSMIMEA()
    {
        // Valid SMIMEA records
        // Format: <usage> <selector> <matching-type> <certificate-data>

        // Valid: Basic SMIMEA with SHA-256 hash (64 hex chars)
        $this->assertTrue(Dns::is_valid_smimea('3 1 1 0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF', false));

        // Valid: Different usage values (0-255)
        $this->assertTrue(Dns::is_valid_smimea('0 1 1 AABBCCDD', false)); // Usage 0 - PKIX-TA
        $this->assertTrue(Dns::is_valid_smimea('1 1 1 AABBCCDD', false)); // Usage 1 - PKIX-EE
        $this->assertTrue(Dns::is_valid_smimea('2 1 1 AABBCCDD', false)); // Usage 2 - DANE-TA
        $this->assertTrue(Dns::is_valid_smimea('3 1 1 AABBCCDD', false)); // Usage 3 - DANE-EE
        $this->assertTrue(Dns::is_valid_smimea('255 1 1 AABBCCDD', false)); // Max usage

        // Valid: Different selector values (0-255)
        $this->assertTrue(Dns::is_valid_smimea('3 0 1 AABBCCDD', false)); // Selector 0 - Full cert
        $this->assertTrue(Dns::is_valid_smimea('3 1 1 AABBCCDD', false)); // Selector 1 - SubjectPublicKeyInfo
        $this->assertTrue(Dns::is_valid_smimea('3 255 1 AABBCCDD', false)); // Max selector

        // Valid: Different matching type values (0-255)
        $this->assertTrue(Dns::is_valid_smimea('3 1 0 AABBCCDD', false)); // Matching 0 - Exact match
        $this->assertTrue(Dns::is_valid_smimea('3 1 1 AABBCCDD', false)); // Matching 1 - SHA-256
        $this->assertTrue(Dns::is_valid_smimea('3 1 2 AABBCCDD', false)); // Matching 2 - SHA-512
        $this->assertTrue(Dns::is_valid_smimea('3 1 255 AABBCCDD', false)); // Max matching type

        // Valid: Lowercase hex
        $this->assertTrue(Dns::is_valid_smimea('3 1 1 aabbccdd', false));
        $this->assertTrue(Dns::is_valid_smimea('3 1 1 0123456789abcdef', false));

        // Valid: Mixed case hex
        $this->assertTrue(Dns::is_valid_smimea('3 1 1 AaBbCcDd', false));

        // Valid: SHA-512 hash (128 hex chars)
        $sha512 = str_repeat('A', 128);
        $this->assertTrue(Dns::is_valid_smimea("3 1 2 $sha512", false));

        // Valid: Long certificate data (full certificate)
        $longCert = str_repeat('ABCD', 100); // 400 hex chars
        $this->assertTrue(Dns::is_valid_smimea("3 0 0 $longCert", false));

        // Valid: Certificate data split with spaces (should be joined)
        $this->assertTrue(Dns::is_valid_smimea('3 1 1 AABB CCDD EEFF', false));
        $this->assertTrue(Dns::is_valid_smimea('3 1 1 AA BB CC DD', false));

        // Valid: Minimum certificate data (2 hex chars = 1 byte)
        $this->assertTrue(Dns::is_valid_smimea('3 1 1 AB', false));

        // Valid: With extra whitespace
        $this->assertTrue(Dns::is_valid_smimea('  3   1   1   AABBCCDD  ', false));

        // Invalid: empty content
        $this->assertFalse(Dns::is_valid_smimea('', false));
        $this->assertFalse(Dns::is_valid_smimea('   ', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_smimea('3', false)); // Only usage
        $this->assertFalse(Dns::is_valid_smimea('3 1', false)); // Only usage and selector
        $this->assertFalse(Dns::is_valid_smimea('3 1 1', false)); // Missing cert data

        // Invalid: usage out of range
        $this->assertFalse(Dns::is_valid_smimea('-1 1 1 AABBCCDD', false)); // Negative
        $this->assertFalse(Dns::is_valid_smimea('256 1 1 AABBCCDD', false)); // Too large
        $this->assertFalse(Dns::is_valid_smimea('1000 1 1 AABBCCDD', false)); // Way too large
        $this->assertFalse(Dns::is_valid_smimea('3.5 1 1 AABBCCDD', false)); // Decimal
        $this->assertFalse(Dns::is_valid_smimea('abc 1 1 AABBCCDD', false)); // Non-numeric

        // Invalid: selector out of range
        $this->assertFalse(Dns::is_valid_smimea('3 -1 1 AABBCCDD', false)); // Negative
        $this->assertFalse(Dns::is_valid_smimea('3 256 1 AABBCCDD', false)); // Too large
        $this->assertFalse(Dns::is_valid_smimea('3 1.5 1 AABBCCDD', false)); // Decimal
        $this->assertFalse(Dns::is_valid_smimea('3 abc 1 AABBCCDD', false)); // Non-numeric

        // Invalid: matching type out of range
        $this->assertFalse(Dns::is_valid_smimea('3 1 -1 AABBCCDD', false)); // Negative
        $this->assertFalse(Dns::is_valid_smimea('3 1 256 AABBCCDD', false)); // Too large
        $this->assertFalse(Dns::is_valid_smimea('3 1 1.5 AABBCCDD', false)); // Decimal
        $this->assertFalse(Dns::is_valid_smimea('3 1 abc AABBCCDD', false)); // Non-numeric

        // Invalid: certificate data issues
        $this->assertFalse(Dns::is_valid_smimea('3 1 1 ', false)); // Empty cert data
        $this->assertFalse(Dns::is_valid_smimea('3 1 1 A', false)); // Odd length (1 hex char)
        $this->assertFalse(Dns::is_valid_smimea('3 1 1 AAA', false)); // Odd length (3 hex chars)
        $this->assertFalse(Dns::is_valid_smimea('3 1 1 AAABB', false)); // Odd length (5 hex chars)

        // Invalid: non-hexadecimal certificate data
        $this->assertFalse(Dns::is_valid_smimea('3 1 1 GGHHII', false)); // G, H, I not hex
        $this->assertFalse(Dns::is_valid_smimea('3 1 1 AABBCC@', false)); // Special char
        $this->assertFalse(Dns::is_valid_smimea('3 1 1 AABB-CCDD', false)); // Dash not allowed
        $this->assertFalse(Dns::is_valid_smimea('3 1 1 AABB:CCDD', false)); // Colon not allowed
        $this->assertFalse(Dns::is_valid_smimea('3 1 1 hello', false)); // Letters not in hex range
        $this->assertFalse(Dns::is_valid_smimea('3 1 1 AABBZ', false)); // Z not hex (odd length too)
    }

    public function testIsValidTKEY()
    {
        // Valid TKEY records
        // Format: <algorithm> <inception> <expiration> <mode> <error> <keysize> <keydata> <othersize> <otherdata>

        // Example from PowerDNS test case
        $this->assertTrue(Dns::is_valid_tkey('gss-tsig. 12345 12345 3 21 4 dGVzdA== 4 dGVzdA==', false));

        // Valid: Different algorithm formats
        $this->assertTrue(Dns::is_valid_tkey('HMAC-MD5.SIG-ALG.REG.INT. 1368386956 1368387016 3 0 16 TkbpD66/Mtgo8GUEFZIwhg== 0', false));
        $this->assertTrue(Dns::is_valid_tkey('hmac-sha256. 1000000 2000000 3 0 32 AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= 0', false));

        // Valid: keysize = 0 (no keydata)
        $this->assertTrue(Dns::is_valid_tkey('gss-tsig. 12345 12345 3 0 0 0', false));
        $this->assertTrue(Dns::is_valid_tkey('test.example.com. 0 4294967295 0 0 0 0', false));

        // Valid: othersize = 0 (no otherdata)
        $this->assertTrue(Dns::is_valid_tkey('gss-tsig. 12345 12345 3 0 4 AAAA 0', false));

        // Valid: Both keydata and otherdata present
        $this->assertTrue(Dns::is_valid_tkey('test.algo. 1000 2000 1 0 8 YWJjZGVmZ2g= 8 aGlqa2xtbm8=', false));

        // Valid: Different time values (32-bit unsigned)
        $this->assertTrue(Dns::is_valid_tkey('algo. 0 0 0 0 0 0', false)); // Min times
        $this->assertTrue(Dns::is_valid_tkey('algo. 4294967295 4294967295 0 0 0 0', false)); // Max times

        // Valid: Different mode values (16-bit unsigned)
        $this->assertTrue(Dns::is_valid_tkey('algo. 1000 2000 0 0 0 0', false)); // Min mode
        $this->assertTrue(Dns::is_valid_tkey('algo. 1000 2000 65535 0 0 0', false)); // Max mode

        // Valid: Different error values (16-bit unsigned)
        $this->assertTrue(Dns::is_valid_tkey('algo. 1000 2000 3 0 0 0', false)); // Min error
        $this->assertTrue(Dns::is_valid_tkey('algo. 1000 2000 3 65535 0 0', false)); // Max error

        // Valid: Different keysize values
        $this->assertTrue(Dns::is_valid_tkey('algo. 1000 2000 3 0 0 0', false)); // keysize 0
        $this->assertTrue(Dns::is_valid_tkey('algo. 1000 2000 3 0 1 AA== 0', false)); // keysize 1
        $this->assertTrue(Dns::is_valid_tkey('algo. 1000 2000 3 0 100 ' . base64_encode(str_repeat('A', 100)) . ' 0', false)); // Large keysize

        // Valid: Different othersize values
        $this->assertTrue(Dns::is_valid_tkey('algo. 1000 2000 3 0 0 0', false)); // othersize 0
        $this->assertTrue(Dns::is_valid_tkey('algo. 1000 2000 3 0 0 1 AA==', false)); // othersize 1
        $this->assertTrue(Dns::is_valid_tkey('algo. 1000 2000 3 0 4 AAAA 10 ' . base64_encode(str_repeat('B', 10)), false));

        // Valid: Algorithm with dots
        $this->assertTrue(Dns::is_valid_tkey('a.b.c.d. 1000 2000 3 0 0 0', false));

        // Valid: Algorithm without trailing dot
        $this->assertTrue(Dns::is_valid_tkey('gss-tsig 12345 12345 3 0 0 0', false));

        // Invalid: empty content
        $this->assertFalse(Dns::is_valid_tkey('', false));
        $this->assertFalse(Dns::is_valid_tkey('   ', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_tkey('algo.', false)); // Only algorithm
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000', false)); // Only 2 fields
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000', false)); // Only 3 fields
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3', false)); // Only 4 fields
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0', false)); // Only 5 fields

        // Invalid: bad algorithm format
        $this->assertFalse(Dns::is_valid_tkey('.invalid 1000 2000 3 0 0 0', false)); // Starts with dot
        $this->assertFalse(Dns::is_valid_tkey('in valid 1000 2000 3 0 0 0', false)); // Contains space
        $this->assertFalse(Dns::is_valid_tkey('in@valid. 1000 2000 3 0 0 0', false)); // Contains @
        $this->assertFalse(Dns::is_valid_tkey('in!valid. 1000 2000 3 0 0 0', false)); // Contains !

        // Invalid: inception out of range
        $this->assertFalse(Dns::is_valid_tkey('algo. -1 2000 3 0 0 0', false)); // Negative
        $this->assertFalse(Dns::is_valid_tkey('algo. 4294967296 2000 3 0 0 0', false)); // Too large (>32-bit)
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000.5 2000 3 0 0 0', false)); // Decimal
        $this->assertFalse(Dns::is_valid_tkey('algo. abc 2000 3 0 0 0', false)); // Non-numeric

        // Invalid: expiration out of range
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 -1 3 0 0 0', false)); // Negative
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 4294967296 3 0 0 0', false)); // Too large
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000.5 3 0 0 0', false)); // Decimal
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 abc 3 0 0 0', false)); // Non-numeric

        // Invalid: mode out of range
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 -1 0 0 0', false)); // Negative
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 65536 0 0 0', false)); // Too large (>16-bit)
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3.5 0 0 0', false)); // Decimal
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 abc 0 0 0', false)); // Non-numeric

        // Invalid: error out of range
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 -1 0 0', false)); // Negative
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 65536 0 0', false)); // Too large
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0.5 0 0', false)); // Decimal
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 abc 0 0', false)); // Non-numeric

        // Invalid: keysize out of range
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 -1 0', false)); // Negative
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 65536 AAAA 0', false)); // Too large
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 1.5 AAAA 0', false)); // Decimal
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 abc AAAA 0', false)); // Non-numeric

        // Invalid: missing keydata when keysize > 0
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 4', false)); // keysize=4 but no keydata

        // Invalid: invalid keydata (not Base64)
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 4 invalid! 0', false)); // Special char
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 4 @#$% 0', false)); // Invalid chars

        // Invalid: missing othersize field
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 0', false)); // No othersize when keysize=0
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 4 AAAA', false)); // No othersize when keysize>0

        // Invalid: othersize out of range
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 0 -1', false)); // Negative
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 0 65536', false)); // Too large
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 0 1.5', false)); // Decimal
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 0 abc', false)); // Non-numeric

        // Invalid: missing otherdata when othersize > 0
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 0 4', false)); // othersize=4 but no otherdata

        // Invalid: invalid otherdata (not Base64)
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 0 4 invalid!', false)); // Special char
        $this->assertFalse(Dns::is_valid_tkey('algo. 1000 2000 3 0 0 4 @#$%', false)); // Invalid chars
    }

    public function testIsValidURI()
    {
        // Valid URI records
        // Format: <priority> <weight> "<target>"

        // Examples from PowerDNS test cases
        $this->assertTrue(Dns::is_valid_uri('10000 1 "ftp://ftp1.example.com/public"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "ftp://ftp1.example.com/public/with/a/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/very/long/url"', false));

        // Valid: Different URI schemes
        $this->assertTrue(Dns::is_valid_uri('10 1 "http://example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "https://example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "ftp://example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "ftps://example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "mailto:user@example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "tel:+1-234-567-8900"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "sip:user@example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "ssh://example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "file:///path/to/file"', false));

        // Valid: Different priority values (16-bit unsigned)
        $this->assertTrue(Dns::is_valid_uri('0 1 "http://example.com"', false)); // Min priority
        $this->assertTrue(Dns::is_valid_uri('100 1 "http://example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('65535 1 "http://example.com"', false)); // Max priority

        // Valid: Different weight values (16-bit unsigned)
        $this->assertTrue(Dns::is_valid_uri('10 0 "http://example.com"', false)); // Min weight
        $this->assertTrue(Dns::is_valid_uri('10 100 "http://example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 65535 "http://example.com"', false)); // Max weight

        // Valid: URIs with paths, queries, and fragments
        $this->assertTrue(Dns::is_valid_uri('10 1 "http://example.com/path/to/resource"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "http://example.com/path?query=value"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "http://example.com/path#fragment"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "http://example.com/path?query=value#fragment"', false));

        // Valid: URIs with ports
        $this->assertTrue(Dns::is_valid_uri('10 1 "http://example.com:8080"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "https://example.com:443"', false));

        // Valid: URIs with authentication
        $this->assertTrue(Dns::is_valid_uri('10 1 "http://user:pass@example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "ftp://anonymous@ftp.example.com"', false));

        // Valid: URIs with special characters (encoded)
        $this->assertTrue(Dns::is_valid_uri('10 1 "http://example.com/path%20with%20spaces"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "http://example.com/path?key=value&other=data"', false));

        // Valid: Custom/less common schemes
        $this->assertTrue(Dns::is_valid_uri('10 1 "git://github.com/repo.git"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "svn://svn.example.com/repo"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "irc://irc.example.com/channel"', false));

        // Valid: Schemes with + . - characters
        $this->assertTrue(Dns::is_valid_uri('10 1 "my-scheme://example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "my.scheme://example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "my+scheme://example.com"', false));
        $this->assertTrue(Dns::is_valid_uri('10 1 "scheme1.2+test://example.com"', false));

        // Invalid: empty content
        $this->assertFalse(Dns::is_valid_uri('', false));
        $this->assertFalse(Dns::is_valid_uri('   ', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_uri('10', false)); // Only priority
        $this->assertFalse(Dns::is_valid_uri('10 1', false)); // Missing target
        $this->assertFalse(Dns::is_valid_uri('10 1 http://example.com', false)); // Target not quoted

        // Invalid: missing quotes around target
        $this->assertFalse(Dns::is_valid_uri('10 1 http://example.com', false));
        $this->assertFalse(Dns::is_valid_uri('10 1 ftp://example.com', false));

        // Invalid: only one quote
        $this->assertFalse(Dns::is_valid_uri('10 1 "http://example.com', false)); // Missing closing quote
        $this->assertFalse(Dns::is_valid_uri('10 1 http://example.com"', false)); // Missing opening quote

        // Invalid: priority out of range
        $this->assertFalse(Dns::is_valid_uri('-1 1 "http://example.com"', false)); // Negative
        $this->assertFalse(Dns::is_valid_uri('65536 1 "http://example.com"', false)); // Too large (>16-bit)
        $this->assertFalse(Dns::is_valid_uri('100000 1 "http://example.com"', false)); // Way too large
        $this->assertFalse(Dns::is_valid_uri('10.5 1 "http://example.com"', false)); // Decimal
        $this->assertFalse(Dns::is_valid_uri('abc 1 "http://example.com"', false)); // Non-numeric

        // Invalid: weight out of range
        $this->assertFalse(Dns::is_valid_uri('10 -1 "http://example.com"', false)); // Negative
        $this->assertFalse(Dns::is_valid_uri('10 65536 "http://example.com"', false)); // Too large
        $this->assertFalse(Dns::is_valid_uri('10 100000 "http://example.com"', false)); // Way too large
        $this->assertFalse(Dns::is_valid_uri('10 1.5 "http://example.com"', false)); // Decimal
        $this->assertFalse(Dns::is_valid_uri('10 abc "http://example.com"', false)); // Non-numeric

        // Invalid: empty target
        $this->assertFalse(Dns::is_valid_uri('10 1 ""', false));

        // Invalid: target without scheme
        $this->assertFalse(Dns::is_valid_uri('10 1 "example.com"', false)); // No scheme
        $this->assertFalse(Dns::is_valid_uri('10 1 "www.example.com"', false)); // No scheme
        $this->assertFalse(Dns::is_valid_uri('10 1 "//example.com"', false)); // Scheme missing

        // Invalid: scheme format errors
        $this->assertFalse(Dns::is_valid_uri('10 1 "123://example.com"', false)); // Scheme starts with number
        $this->assertFalse(Dns::is_valid_uri('10 1 "-scheme://example.com"', false)); // Scheme starts with dash
        $this->assertFalse(Dns::is_valid_uri('10 1 "+scheme://example.com"', false)); // Scheme starts with plus
        $this->assertFalse(Dns::is_valid_uri('10 1 ".scheme://example.com"', false)); // Scheme starts with dot
        $this->assertFalse(Dns::is_valid_uri('10 1 "sch eme://example.com"', false)); // Scheme with space
        $this->assertFalse(Dns::is_valid_uri('10 1 "sch@me://example.com"', false)); // Scheme with @

        // Invalid: malformed URIs (still have scheme but clearly wrong)
        $this->assertFalse(Dns::is_valid_uri('10 1 ":no-scheme"', false)); // Starts with colon
    }

    public function testIsValidTLSA()
    {
        // TLSA uses same format as SMIMEA - test basic cases
        $this->assertTrue(Dns::is_valid_tlsa('0 0 0 308201f43082015da003020102020900ac547c5557870ec7300d06092a864886f70d010105050030133111300f06035504030c087265632e74657374301e170d3133303531323139343830395a170d3133303631313139343830395a30133111300f06035504030c087265632e7465737430819f300d06092a864886f70d010101050003818d0030818902818100d282bb968dfdec0e5d13dfcc0a36ed73178581424e10a37c89d3014204933b3a8c1159fdecb221afe4168883d2d00ac1f15fca4614fbd5e05de2e37ad0fbad8b7748dddbcf30b39e80466c61c733415e72b9f42d5fad0bf35f041eb5631eded00314c66c4878b351416e5c6b9096f2a7088a24387e5d0149c523739f84f502c70203010001a350304e301d0603551d0e0416041473715bbfd9bc2b824112f858586f166aafb99482301f0603551d2304183016801473715bbfd9bc2b824112f858586f166aafb99482300c0603551d13040530030101ff300d06092a864886f70d0101050500038181005550f1d64139ab0e86c5b303fc69015d1676ca95931071ae41884656c71c116a38138ecf63054b350dc78983cb4a83288dbc81c5a659a56cc6843d5452c3e98449b94a0cf0c0cd7190c96caa5f0ee9a3bef7e75002be4a233673852bdf1a5fd306a7080eb4fead9b3ad162074b5f007e9156e220302dea8c700868a12577e7c4', false));
        $this->assertTrue(Dns::is_valid_tlsa('1 0 0 308201f43082015da003020102020900ac547c5557870ec7300d06092a864886f70d010105050030133111300f06035504030c087265632e74657374301e170d3133303531323139343830395a170d3133303631313139343830395a30133111300f06035504030c087265632e7465737430819f300d06092a864886f70d010101050003818d0030818902818100d282bb968dfdec0e5d13dfcc0a36ed73178581424e10a37c89d3014204933b3a8c1159fdecb221afe4168883d2d00ac1f15fca4614fbd5e05de2e37ad0fbad8b7748dddbcf30b39e80466c61c733415e72b9f42d5fad0bf35f041eb5631eded00314c66c4878b351416e5c6b9096f2a7088a24387e5d0149c523739f84f502c70203010001a350304e301d0603551d0e0416041473715bbfd9bc2b824112f858586f166aafb99482301f0603551d2304183016801473715bbfd9bc2b824112f858586f166aafb99482300c0603551d13040530030101ff300d06092a864886f70d0101050500038181005550f1d64139ab0e86c5b303fc69015d1676ca95931071ae41884656c71c116a38138ecf63054b350dc78983cb4a83288dbc81c5a659a56cc6843d5452c3e98449b94a0cf0c0cd7190c96caa5f0ee9a3bef7e75002be4a233673852bdf1a5fd306a7080eb4fead9b3ad162074b5f007e9156e220302dea8c700868a12577e7c4', false));
        $this->assertTrue(Dns::is_valid_tlsa('3 1 1 AABBCCDD', false));
        $this->assertFalse(Dns::is_valid_tlsa('', false));
        $this->assertFalse(Dns::is_valid_tlsa('3 1', false));
    }

    public function testIsValidSSHFP()
    {
        // Valid SSHFP records from PowerDNS test
        $this->assertTrue(Dns::is_valid_sshfp('1 1 aa65e3415a50d9b3519c2b17aceb815fc2538d88', false));

        // Valid: Different algorithms
        $this->assertTrue(Dns::is_valid_sshfp('1 1 AABBCCDD', false)); // RSA, SHA-1
        $this->assertTrue(Dns::is_valid_sshfp('2 2 AABBCCDD', false)); // DSS, SHA-256
        $this->assertTrue(Dns::is_valid_sshfp('3 1 AABBCCDD', false)); // ECDSA, SHA-1
        $this->assertTrue(Dns::is_valid_sshfp('4 2 AABBCCDD', false)); // Ed25519, SHA-256

        // Valid: Different fingerprint types
        $this->assertTrue(Dns::is_valid_sshfp('1 1 ' . str_repeat('A', 40), false)); // SHA-1 (40 hex chars = 20 bytes)
        $this->assertTrue(Dns::is_valid_sshfp('1 2 ' . str_repeat('B', 64), false)); // SHA-256 (64 hex chars = 32 bytes)

        // Valid: Lowercase and mixed case
        $this->assertTrue(Dns::is_valid_sshfp('1 1 aabbccdd', false));
        $this->assertTrue(Dns::is_valid_sshfp('1 1 AaBbCcDd', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_sshfp('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_sshfp('1', false));
        $this->assertFalse(Dns::is_valid_sshfp('1 1', false));

        // Invalid: algorithm out of range
        $this->assertFalse(Dns::is_valid_sshfp('-1 1 AABBCCDD', false));
        $this->assertFalse(Dns::is_valid_sshfp('256 1 AABBCCDD', false));

        // Invalid: fptype out of range
        $this->assertFalse(Dns::is_valid_sshfp('1 -1 AABBCCDD', false));
        $this->assertFalse(Dns::is_valid_sshfp('1 256 AABBCCDD', false));

        // Invalid: non-hex fingerprint
        $this->assertFalse(Dns::is_valid_sshfp('1 1 GGHHII', false));
        $this->assertFalse(Dns::is_valid_sshfp('1 1 AABB@#', false));

        // Invalid: odd length fingerprint
        $this->assertFalse(Dns::is_valid_sshfp('1 1 AAA', false));
    }

    public function testIsValidNAPTR()
    {
        // Valid NAPTR records from PowerDNS test
        $this->assertTrue(Dns::is_valid_naptr('100 10 "" "" "/urn:cid:.+@([^\\\\.]+\\\\.)(.*)$/\\\\2/i" .', false));
        $this->assertTrue(Dns::is_valid_naptr('100 50 "s" "http+I2L+I2C+I2R" "" _http._tcp.rec.test.', false));

        // Valid: Simple cases
        $this->assertTrue(Dns::is_valid_naptr('100 10 "u" "E2U+sip" "!^.*$!sip:info@example.com!" .', false));
        $this->assertTrue(Dns::is_valid_naptr('100 10 "a" "SIP+D2T" "" _sip._tcp.example.com.', false));

        // Valid: Empty fields
        $this->assertTrue(Dns::is_valid_naptr('0 0 "" "" "" example.com.', false));

        // Valid: Different order/preference values
        $this->assertTrue(Dns::is_valid_naptr('0 0 "" "" "" .', false)); // Min values
        $this->assertTrue(Dns::is_valid_naptr('65535 65535 "" "" "" .', false)); // Max values

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_naptr('', false));

        // Invalid: missing quotes
        $this->assertFalse(Dns::is_valid_naptr('100 10 u E2U+sip !^.*$!sip:info@example.com! .', false));

        // Invalid: order out of range
        $this->assertFalse(Dns::is_valid_naptr('-1 10 "" "" "" .', false));
        $this->assertFalse(Dns::is_valid_naptr('65536 10 "" "" "" .', false));

        // Invalid: preference out of range
        $this->assertFalse(Dns::is_valid_naptr('100 -1 "" "" "" .', false));
        $this->assertFalse(Dns::is_valid_naptr('100 65536 "" "" "" .', false));

        // Invalid: bad replacement
        $this->assertFalse(Dns::is_valid_naptr('100 10 "" "" "" @invalid', false));
    }

    public function testIsValidRP()
    {
        // Valid RP records from PowerDNS test
        $this->assertTrue(Dns::is_valid_rp('admin.rec.test. admin-info.rec.test.', false));
        $this->assertTrue(Dns::is_valid_rp('admin.example.com. admin-info.example.com.', false));

        // Valid: Simple cases
        $this->assertTrue(Dns::is_valid_rp('john.example.com. john-info.example.com.', false));
        $this->assertTrue(Dns::is_valid_rp('admin.test. info.test.', false));

        // Valid: Without trailing dots
        $this->assertTrue(Dns::is_valid_rp('admin.example.com admin-info.example.com', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_rp('', false));

        // Invalid: missing field
        $this->assertFalse(Dns::is_valid_rp('admin.example.com.', false));

        // Invalid: bad domain names
        $this->assertFalse(Dns::is_valid_rp('@invalid.com. info.com.', false));
        $this->assertFalse(Dns::is_valid_rp('admin.com. @invalid.com.', false));
    }

    public function testIsValidDNSKEY()
    {
        // Valid DNSKEY record from PowerDNS test
        $this->assertTrue(Dns::is_valid_dnskey('257 3 5 AwEAAZVtlHc8O4TVmlGx/PGJTc7hbVjMR7RywxLuAm1dqgyHvgNRD7chYLsALOdZKW6VRvusbyhoOPilnh8XpucBDqjGD6lIemsURz7drZEqcLupVA0TPxXABZ6auJ3jumqIhSOcLj9rpSwI4xuWt0yu6LR9tL2q8+A0yEZxcAaKS+Wq0fExJ93NxgXl1/fY+JcYQvonjd31GxXXef9uf0exXyzowh5h8+IIBETU+ZiYVB5BqiwkICZL/OX57idm99ycA2/tIen66F8u2ueTvgPcecnoqHvW0MtLQKzeNmqdGNthHhV5di0SZdMZQeo/izs68uN2WzqQDZy9Ec2JwBTbxWE=', false));

        // Valid: Different flags
        $this->assertTrue(Dns::is_valid_dnskey('256 3 5 AAAA', false)); // Zone key
        $this->assertTrue(Dns::is_valid_dnskey('257 3 5 AAAA', false)); // Secure entry point

        // Valid: Different algorithms
        $this->assertTrue(Dns::is_valid_dnskey('257 3 5 AAAA', false)); // RSA/SHA-1
        $this->assertTrue(Dns::is_valid_dnskey('257 3 8 AAAA', false)); // RSA/SHA-256
        $this->assertTrue(Dns::is_valid_dnskey('257 3 13 AAAA', false)); // ECDSA

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_dnskey('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_dnskey('257', false));
        $this->assertFalse(Dns::is_valid_dnskey('257 3', false));
        $this->assertFalse(Dns::is_valid_dnskey('257 3 5', false));

        // Invalid: wrong protocol
        $this->assertFalse(Dns::is_valid_dnskey('257 2 5 AAAA', false)); // Must be 3
        $this->assertFalse(Dns::is_valid_dnskey('257 4 5 AAAA', false)); // Must be 3

        // Invalid: flags out of range
        $this->assertFalse(Dns::is_valid_dnskey('-1 3 5 AAAA', false));
        $this->assertFalse(Dns::is_valid_dnskey('65536 3 5 AAAA', false));

        // Invalid: algorithm out of range
        $this->assertFalse(Dns::is_valid_dnskey('257 3 -1 AAAA', false));
        $this->assertFalse(Dns::is_valid_dnskey('257 3 256 AAAA', false));

        // Invalid: bad Base64
        $this->assertFalse(Dns::is_valid_dnskey('257 3 5 !!!', false));
    }

    public function testIsValidNSEC()
    {
        // Valid NSEC records from PowerDNS test
        $this->assertTrue(Dns::is_valid_nsec('a.rec.test. A NS SOA MX AAAA RRSIG NSEC DNSKEY', false));

        // Valid: Simple cases
        $this->assertTrue(Dns::is_valid_nsec('example.com. A AAAA', false));
        $this->assertTrue(Dns::is_valid_nsec('sub.example.com. NS DS RRSIG NSEC', false));

        // Valid: Single type
        $this->assertTrue(Dns::is_valid_nsec('example.com. A', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_nsec('', false));

        // Invalid: missing type list
        $this->assertFalse(Dns::is_valid_nsec('example.com.', false));

        // Invalid: bad domain
        $this->assertFalse(Dns::is_valid_nsec('@invalid A', false));

        // Invalid: lowercase types (must be uppercase)
        $this->assertFalse(Dns::is_valid_nsec('example.com. a aaaa', false));
    }

    public function testIsValidNSEC3()
    {
        // Valid NSEC3 record from PowerDNS test
        $this->assertTrue(Dns::is_valid_nsec3('1 1 1 f00b RPF1JGFCCNFA7STPTIJ9FPFNM40A4FLL NS SOA RRSIG DNSKEY NSEC3PARAM', false));

        // Valid: No salt
        $this->assertTrue(Dns::is_valid_nsec3('1 0 0 - AABBCCDD A AAAA', false));

        // Valid: Different algorithms
        $this->assertTrue(Dns::is_valid_nsec3('1 0 10 aabbcc A2B3C4D5 NS', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_nsec3('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_nsec3('1 1 1 f00b', false)); // Missing next hash

        // Invalid: algorithm out of range
        $this->assertFalse(Dns::is_valid_nsec3('-1 0 0 - AABBCCDD A', false));
        $this->assertFalse(Dns::is_valid_nsec3('256 0 0 - AABBCCDD A', false));

        // Invalid: flags out of range
        $this->assertFalse(Dns::is_valid_nsec3('1 -1 0 - AABBCCDD A', false));
        $this->assertFalse(Dns::is_valid_nsec3('1 256 0 - AABBCCDD A', false));

        // Invalid: iterations out of range
        $this->assertFalse(Dns::is_valid_nsec3('1 0 -1 - AABBCCDD A', false));
        $this->assertFalse(Dns::is_valid_nsec3('1 0 65536 - AABBCCDD A', false));

        // Invalid: bad salt
        $this->assertFalse(Dns::is_valid_nsec3('1 0 0 GHI AABBCCDD A', false)); // G, H, I not hex

        // Invalid: bad Base32hex hash
        $this->assertTrue(Dns::is_valid_nsec3('1 0 0 - aabbccdd A', false)); // Lowercase is valid Base32hex
        $this->assertFalse(Dns::is_valid_nsec3('1 0 0 - WXYZ A', false)); // W, X, Y, Z not valid Base32hex (only 0-9, A-V)
    }

    public function testIsValidNSEC3PARAM()
    {
        // Valid NSEC3PARAM record from PowerDNS test
        $this->assertTrue(Dns::is_valid_nsec3param('1 0 1 f00b', false));

        // Valid: No salt
        $this->assertTrue(Dns::is_valid_nsec3param('1 0 0 -', false));

        // Valid: Different values
        $this->assertTrue(Dns::is_valid_nsec3param('1 1 100 aabbccdd', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_nsec3param('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_nsec3param('1 0 1', false));

        // Invalid: algorithm out of range
        $this->assertFalse(Dns::is_valid_nsec3param('-1 0 0 -', false));
        $this->assertFalse(Dns::is_valid_nsec3param('256 0 0 -', false));

        // Invalid: bad salt
        $this->assertFalse(Dns::is_valid_nsec3param('1 0 0 GHI', false));
    }

    public function testIsValidRRSIG()
    {
        // Valid RRSIG record from PowerDNS test
        $this->assertTrue(Dns::is_valid_rrsig('SOA 8 3 300 20130523000000 20130509000000 54216 rec.test. ecWKD/OsdAiXpbM/sgPT82KVD/WiQnnqcxoJgiH3ixHa+LOAcYU7FG7V4BRRJxLriY1e0rB2gAs3kCel9D4bzfK6wAqG4Di/eHUgHptRlaR2ycELJ4t1pjzrnuGiIzA1wM2izRmeE+Xoy1367Qu0pOz5DLzTfQITWFsB2iUzN4Y=', false));

        // RRSIG uses same format as SIG - test basic case
        $this->assertTrue(Dns::is_valid_rrsig('A 8 2 3600 20250101000000 20241201000000 12345 example.com. AAAA', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_rrsig('', false));

        // Invalid: missing fields (reuses SIG validation)
        $this->assertFalse(Dns::is_valid_rrsig('SOA 8 3', false));
    }

    public function testIsValidTSIG()
    {
        // Valid TSIG records from PowerDNS test
        $this->assertTrue(Dns::is_valid_tsig('HMAC-MD5.SIG-ALG.REG.INT. 1368386956 60 16 TkbpD66/Mtgo8GUEFZIwhg== 12345 0 0', false));
        $this->assertTrue(Dns::is_valid_tsig('HMAC-MD5.SIG-ALG.REG.INT. 1368386956 60 16 TkbpD66/Mtgo8GUEFZIwhg== 12345 18 16 TkbpD66/Mtgo8GUEFZIwhg==', false));

        // Valid: Different algorithms
        $this->assertTrue(Dns::is_valid_tsig('hmac-sha256. 1000000 60 32 AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= 1234 0 0', false));

        // Valid: No MAC (macsize=0)
        $this->assertTrue(Dns::is_valid_tsig('hmac-md5. 1000000 60 0 AA== 1234 0 0', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_tsig('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000', false));
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 16 AAAA 1234 0', false)); // Missing other-len

        // Invalid: bad algorithm
        $this->assertFalse(Dns::is_valid_tsig('@invalid 1000000 60 16 AAAA 1234 0 0', false));

        // Invalid: time-signed negative
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. -1 60 16 AAAA 1234 0 0', false));

        // Invalid: fudge out of range
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 -1 16 AAAA 1234 0 0', false));
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 65536 16 AAAA 1234 0 0', false));

        // Invalid: mac-size out of range
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 -1 AAAA 1234 0 0', false));
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 65536 AAAA 1234 0 0', false));

        // Invalid: bad Base64 MAC
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 16 !!!! 1234 0 0', false));

        // Invalid: original-id out of range
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 16 AAAA -1 0 0', false));
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 16 AAAA 65536 0 0', false));

        // Invalid: error out of range
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 16 AAAA 1234 -1 0', false));
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 16 AAAA 1234 65536 0', false));

        // Invalid: other-len out of range
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 16 AAAA 1234 0 -1', false));
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 16 AAAA 1234 0 65536', false));

        // Invalid: missing other-data when other-len > 0
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 16 AAAA 1234 0 16', false));

        // Invalid: bad Base64 other-data
        $this->assertFalse(Dns::is_valid_tsig('hmac-md5. 1000000 60 16 AAAA 1234 0 16 !!!!', false));
    }

    public function testIsValidEUI48()
    {
        // Valid EUI48 from PowerDNS test
        $this->assertTrue(Dns::is_valid_eui48('00-11-22-33-44-55', false));

        // Valid: Different octets
        $this->assertTrue(Dns::is_valid_eui48('AA-BB-CC-DD-EE-FF', false));
        $this->assertTrue(Dns::is_valid_eui48('aa-bb-cc-dd-ee-ff', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_eui48('', false));

        // Invalid: wrong format (colons instead of dashes)
        $this->assertFalse(Dns::is_valid_eui48('00:11:22:33:44:55', false));

        // Invalid: missing octets
        $this->assertFalse(Dns::is_valid_eui48('00-11-22-33-44', false));

        // Invalid: too many octets
        $this->assertFalse(Dns::is_valid_eui48('00-11-22-33-44-55-66', false));

        // Invalid: non-hex characters
        $this->assertFalse(Dns::is_valid_eui48('GG-11-22-33-44-55', false));

        // Invalid: single digit octets
        $this->assertFalse(Dns::is_valid_eui48('0-1-2-3-4-5', false));
    }

    public function testIsValidEUI64()
    {
        // Valid EUI64 from PowerDNS test
        $this->assertTrue(Dns::is_valid_eui64('00-11-22-33-44-55-66-77', false));

        // Valid: Different octets
        $this->assertTrue(Dns::is_valid_eui64('AA-BB-CC-DD-EE-FF-00-11', false));
        $this->assertTrue(Dns::is_valid_eui64('aa-bb-cc-dd-ee-ff-00-11', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_eui64('', false));

        // Invalid: wrong format (colons instead of dashes)
        $this->assertFalse(Dns::is_valid_eui64('00:11:22:33:44:55:66:77', false));

        // Invalid: missing octets
        $this->assertFalse(Dns::is_valid_eui64('00-11-22-33-44-55-66', false));

        // Invalid: too many octets
        $this->assertFalse(Dns::is_valid_eui64('00-11-22-33-44-55-66-77-88', false));

        // Invalid: non-hex characters
        $this->assertFalse(Dns::is_valid_eui64('GG-11-22-33-44-55-66-77', false));

        // Invalid: single digit octets
        $this->assertFalse(Dns::is_valid_eui64('0-1-2-3-4-5-6-7', false));
    }

    public function testIsValidNID()
    {
        // Valid NID records from PowerDNS test
        $this->assertTrue(Dns::is_valid_nid('15 0123:4567:89AB:CDEF', false));
        $this->assertTrue(Dns::is_valid_nid('15 2001:0DB8:1234:ABCD', false));

        // Valid: Different preferences
        $this->assertTrue(Dns::is_valid_nid('0 1234:5678:9ABC:DEF0', false));
        $this->assertTrue(Dns::is_valid_nid('65535 ABCD:EF01:2345:6789', false));

        // Valid: Lowercase hex
        $this->assertTrue(Dns::is_valid_nid('10 abcd:ef01:2345:6789', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_nid('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_nid('15', false));
        $this->assertFalse(Dns::is_valid_nid('15 ', false));

        // Invalid: too many fields
        $this->assertFalse(Dns::is_valid_nid('15 1234:5678:9ABC:DEF0 extra', false));

        // Invalid: preference out of range
        $this->assertFalse(Dns::is_valid_nid('-1 1234:5678:9ABC:DEF0', false));
        $this->assertFalse(Dns::is_valid_nid('65536 1234:5678:9ABC:DEF0', false));

        // Invalid: bad node-id format
        $this->assertFalse(Dns::is_valid_nid('15 1234:5678:9ABC', false)); // Too few segments
        $this->assertFalse(Dns::is_valid_nid('15 1234:5678:9ABC:DEF0:1234', false)); // Too many segments
        $this->assertFalse(Dns::is_valid_nid('15 123:5678:9ABC:DEF0', false)); // Wrong segment length
        $this->assertFalse(Dns::is_valid_nid('15 GHIJ:5678:9ABC:DEF0', false)); // Non-hex
    }

    public function testIsValidKX()
    {
        // Valid KX from PowerDNS test
        $this->assertTrue(Dns::is_valid_kx('10 mail.rec.test.', false));

        // Valid: Different preferences
        $this->assertTrue(Dns::is_valid_kx('0 kx.example.com.', false));
        $this->assertTrue(Dns::is_valid_kx('65535 kx.example.org', false));

        // Valid: Without trailing dot
        $this->assertTrue(Dns::is_valid_kx('10 mail.example.com', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_kx('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_kx('10', false));
        $this->assertFalse(Dns::is_valid_kx('10 ', false));

        // Invalid: too many fields
        $this->assertFalse(Dns::is_valid_kx('10 mail.example.com extra', false));

        // Invalid: preference out of range
        $this->assertFalse(Dns::is_valid_kx('-1 mail.example.com', false));
        $this->assertFalse(Dns::is_valid_kx('65536 mail.example.com', false));

        // Invalid: bad domain name
        $this->assertFalse(Dns::is_valid_kx('10 -invalid.com', false));
        $this->assertFalse(Dns::is_valid_kx('10 invalid-.com', false));
    }

    public function testIsValidIPSECKEY()
    {
        // Valid IPSECKEY from PowerDNS tests
        $this->assertTrue(Dns::is_valid_ipseckey('255 0 0', false)); // No gateway, no key
        $this->assertTrue(Dns::is_valid_ipseckey('255 0 1 V19hwufL6LJARVIxzHDyGdvZ7dbQE0Kyl18yPIWj/sbCcsBbz7zO6Q2qgdzmWI3OvGNne2nxflhorhefKIMsUg==', false));
        $this->assertTrue(Dns::is_valid_ipseckey('255 1 0 127.0.0.1', false)); // IPv4 gateway
        $this->assertTrue(Dns::is_valid_ipseckey('255 2 0 fe80::250:56ff:fe9b:114', false)); // IPv6 gateway
        $this->assertTrue(Dns::is_valid_ipseckey('10 1 1 127.0.0.1 V19hwufL6LJARVIxzHDyGdvZ7dbQE0Kyl18yPIWj/sbCcsBbz7zO6Q2qgdzmWI3OvGNne2nxflhorhefKIMsUg==', false));
        $this->assertTrue(Dns::is_valid_ipseckey('10 2 1 fe80::250:56ff:fe9b:114 V19hwufL6LJARVIxzHDyGdvZ7dbQE0Kyl18yPIWj/sbCcsBbz7zO6Q2qgdzmWI3OvGNne2nxflhorhefKIMsUg==', false));
        $this->assertTrue(Dns::is_valid_ipseckey('10 3 1 gw.rec.test. V19hwufL6LJARVIxzHDyGdvZ7dbQE0Kyl18yPIWj/sbCcsBbz7zO6Q2qgdzmWI3OvGNne2nxflhorhefKIMsUg==', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_ipseckey('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_ipseckey('255', false));
        $this->assertFalse(Dns::is_valid_ipseckey('255 0', false));

        // Invalid: precedence out of range
        $this->assertFalse(Dns::is_valid_ipseckey('-1 0 0', false));
        $this->assertFalse(Dns::is_valid_ipseckey('256 0 0', false));

        // Invalid: gateway-type out of range
        $this->assertFalse(Dns::is_valid_ipseckey('10 -1 0', false));
        $this->assertFalse(Dns::is_valid_ipseckey('10 4 0', false));

        // Invalid: algorithm out of range
        $this->assertFalse(Dns::is_valid_ipseckey('10 0 -1', false));
        $this->assertFalse(Dns::is_valid_ipseckey('10 0 256', false));

        // Invalid: missing gateway for type 1
        $this->assertFalse(Dns::is_valid_ipseckey('10 1 0', false));

        // Invalid: bad IPv4 gateway
        $this->assertFalse(Dns::is_valid_ipseckey('10 1 0 999.999.999.999', false));
        $this->assertFalse(Dns::is_valid_ipseckey('10 1 0 not-an-ip', false));

        // Invalid: missing gateway for type 2
        $this->assertFalse(Dns::is_valid_ipseckey('10 2 0', false));

        // Invalid: bad IPv6 gateway
        $this->assertFalse(Dns::is_valid_ipseckey('10 2 0 gggg::1', false));
        $this->assertFalse(Dns::is_valid_ipseckey('10 2 0 not-an-ipv6', false));

        // Invalid: missing gateway for type 3
        $this->assertFalse(Dns::is_valid_ipseckey('10 3 0', false));

        // Invalid: bad domain gateway
        $this->assertFalse(Dns::is_valid_ipseckey('10 3 0 -invalid.com', false));

        // Invalid: bad Base64 public key
        $this->assertFalse(Dns::is_valid_ipseckey('10 0 1 !!!notbase64!!!', false));
        $this->assertFalse(Dns::is_valid_ipseckey('10 1 1 127.0.0.1 !!!notbase64!!!', false));
    }

    public function testIsValidDLV()
    {
        // Valid DLV from PowerDNS test (same format as DS)
        $this->assertTrue(Dns::is_valid_dlv('20642 8 2 04443abe7e94c3985196beae5d548c727b044dda5151e60d7cd76a9fd931d00e', false));

        // Valid: Different key tags and algorithms
        $this->assertTrue(Dns::is_valid_dlv('0 5 1 1234567890abcdef', false));
        $this->assertTrue(Dns::is_valid_dlv('65535 255 255 aabbccddeeff', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_dlv('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_dlv('20642 8 2', false));

        // Invalid: negative values (DS validation uses simple regex)
        $this->assertFalse(Dns::is_valid_dlv('-1 8 2 aabbccdd', false));

        // Invalid: non-numeric values
        $this->assertFalse(Dns::is_valid_dlv('abc 8 2 aabbccdd', false));
        $this->assertFalse(Dns::is_valid_dlv('100 xyz 2 aabbccdd', false));
        $this->assertFalse(Dns::is_valid_dlv('100 8 xyz aabbccdd', false));

        // Invalid: non-hex digest
        $this->assertFalse(Dns::is_valid_dlv('100 8 2 gghhiijj', false));
    }

    public function testIsValidKEY()
    {
        // Valid KEY from PowerDNS test (same format as DNSKEY)
        $this->assertTrue(Dns::is_valid_key('0 3 3 V19hwufL6LJARVIxzHDyGdvZ7dbQE0Kyl18yPIWj/sbCcsBbz7zO6Q2qgdzmWI3OvGNne2nxflhorhefKIMsUg==', false));

        // Valid: Different flags and algorithms
        $this->assertTrue(Dns::is_valid_key('256 3 5 AQEAAAAB', false));
        $this->assertTrue(Dns::is_valid_key('65535 3 255 AQEAAAAB', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_key('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_key('0 3', false));

        // Invalid: flags out of range
        $this->assertFalse(Dns::is_valid_key('-1 3 3 AQEAAAAB', false));
        $this->assertFalse(Dns::is_valid_key('65536 3 3 AQEAAAAB', false));

        // Invalid: protocol not 3
        $this->assertFalse(Dns::is_valid_key('256 0 3 AQEAAAAB', false));
        $this->assertFalse(Dns::is_valid_key('256 2 3 AQEAAAAB', false));

        // Invalid: algorithm out of range
        $this->assertFalse(Dns::is_valid_key('256 3 -1 AQEAAAAB', false));
        $this->assertFalse(Dns::is_valid_key('256 3 256 AQEAAAAB', false));

        // Invalid: bad Base64 key
        $this->assertFalse(Dns::is_valid_key('256 3 5 !!!notbase64!!!', false));
    }

    public function testIsValidMINFO()
    {
        // Valid MINFO records
        $this->assertTrue(Dns::is_valid_minfo('admin.example.com. errors.example.com.', false));
        $this->assertTrue(Dns::is_valid_minfo('admin.test. errors.test.', false));

        // Valid: Without trailing dots
        $this->assertTrue(Dns::is_valid_minfo('admin.example.com errors.example.com', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_minfo('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_minfo('admin.example.com', false));
        $this->assertFalse(Dns::is_valid_minfo('admin.example.com ', false));

        // Invalid: too many fields
        $this->assertFalse(Dns::is_valid_minfo('admin.example.com errors.example.com extra', false));

        // Invalid: bad domain names
        $this->assertFalse(Dns::is_valid_minfo('-invalid.com errors.test.', false));
        $this->assertFalse(Dns::is_valid_minfo('admin.test. invalid-.com', false));
    }

    public function testIsValidMR()
    {
        // Valid MR from PowerDNS test
        $this->assertTrue(Dns::is_valid_mr('newmailbox.rec.test.', false));
        $this->assertTrue(Dns::is_valid_mr('newmailbox.example.com.', false));

        // Valid: Without trailing dot
        $this->assertTrue(Dns::is_valid_mr('newmailbox.example.com', false));

        // Valid: Simple domain
        $this->assertTrue(Dns::is_valid_mr('mailbox.test', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_mr('', false));

        // Invalid: bad domain names
        $this->assertFalse(Dns::is_valid_mr('-invalid.com', false));
        $this->assertFalse(Dns::is_valid_mr('invalid-.com', false));
        $this->assertFalse(Dns::is_valid_mr('invalid..com', false));
    }

    public function testIsValidWKS()
    {
        // Valid WKS records
        $this->assertTrue(Dns::is_valid_wks('192.168.1.1 tcp 25 80 443', false));
        $this->assertTrue(Dns::is_valid_wks('10.0.0.1 udp 53 123', false));
        $this->assertTrue(Dns::is_valid_wks('172.16.0.1 6 22 23', false)); // Protocol number

        // Valid: With service names
        $this->assertTrue(Dns::is_valid_wks('192.168.1.1 tcp smtp http https', false));
        $this->assertTrue(Dns::is_valid_wks('192.168.1.1 udp dns ntp', false));

        // Valid: Mixed port numbers and names
        $this->assertTrue(Dns::is_valid_wks('192.168.1.1 tcp 25 http 443', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_wks('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_wks('192.168.1.1', false));
        $this->assertFalse(Dns::is_valid_wks('192.168.1.1 tcp', false));

        // Invalid: bad IPv4 address
        $this->assertFalse(Dns::is_valid_wks('999.999.999.999 tcp 80', false));
        $this->assertFalse(Dns::is_valid_wks('not-an-ip tcp 80', false));
        $this->assertFalse(Dns::is_valid_wks('::1 tcp 80', false)); // IPv6 not allowed

        // Invalid: protocol out of range
        $this->assertFalse(Dns::is_valid_wks('192.168.1.1 -1 80', false));
        $this->assertFalse(Dns::is_valid_wks('192.168.1.1 256 80', false));

        // Invalid: bad protocol name
        $this->assertFalse(Dns::is_valid_wks('192.168.1.1 123abc 80', false)); // Can't start with digit
        $this->assertFalse(Dns::is_valid_wks('192.168.1.1 tcp! 80', false));

        // Invalid: port out of range
        $this->assertFalse(Dns::is_valid_wks('192.168.1.1 tcp -1', false));
        $this->assertFalse(Dns::is_valid_wks('192.168.1.1 tcp 65536', false));

        // Invalid: bad service name
        $this->assertFalse(Dns::is_valid_wks('192.168.1.1 tcp 123abc', false)); // Can't start with digit
        $this->assertFalse(Dns::is_valid_wks('192.168.1.1 tcp http!', false));
    }

    public function testIsValidA6()
    {
        // Valid A6 records (deprecated)
        $this->assertTrue(Dns::is_valid_a6('0 ::1', false));
        $this->assertTrue(Dns::is_valid_a6('64 ::1 prefix.example.com', false));
        $this->assertTrue(Dns::is_valid_a6('128 prefix.example.com', false));

        // Valid: Different prefix lengths
        $this->assertTrue(Dns::is_valid_a6('0 2001:db8::1', false));
        $this->assertTrue(Dns::is_valid_a6('64 2001:db8::1 prefix.test.', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_a6('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_a6('64', false));

        // Invalid: prefix length out of range
        $this->assertFalse(Dns::is_valid_a6('-1 ::1', false));
        $this->assertFalse(Dns::is_valid_a6('129 ::1', false));

        // Invalid: non-numeric prefix length
        $this->assertFalse(Dns::is_valid_a6('abc ::1', false));
    }

    public function testIsValidCSYNC()
    {
        // Valid CSYNC from PowerDNS test
        $this->assertTrue(Dns::is_valid_csync('66 3 A NS AAAA', false));

        // Valid: Different serials and flags
        $this->assertTrue(Dns::is_valid_csync('0 0 A', false));
        $this->assertTrue(Dns::is_valid_csync('4294967295 65535 NS SOA', false));

        // Valid: Multiple types
        $this->assertTrue(Dns::is_valid_csync('2023010100 1 A AAAA MX NS', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_csync('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_csync('66', false));
        $this->assertFalse(Dns::is_valid_csync('66 3', false));

        // Invalid: serial out of range
        $this->assertFalse(Dns::is_valid_csync('-1 3 A', false));
        $this->assertFalse(Dns::is_valid_csync('4294967296 3 A', false));

        // Invalid: flags out of range
        $this->assertFalse(Dns::is_valid_csync('66 -1 A', false));
        $this->assertFalse(Dns::is_valid_csync('66 65536 A', false));

        // Invalid: lowercase type
        $this->assertFalse(Dns::is_valid_csync('66 3 a', false));
        $this->assertFalse(Dns::is_valid_csync('66 3 A ns', false));
    }

    public function testIsValidZONEMD()
    {
        // Valid ZONEMD from PowerDNS test
        $this->assertTrue(Dns::is_valid_zonemd('2018031900 1 1 a3b69bad980a3504e1cffcb0fd6397f93848071c93151f552ae2f6b1711d4bd2d8b39808226d7b9db71e34b72077f8fe', false));

        // Valid: Different values
        $this->assertTrue(Dns::is_valid_zonemd('0 0 0 abcdef', false));
        $this->assertTrue(Dns::is_valid_zonemd('4294967295 255 255 AABBCCDD', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_zonemd('', false));

        // Invalid: wrong number of fields
        $this->assertFalse(Dns::is_valid_zonemd('2018031900 1 1', false));
        $this->assertFalse(Dns::is_valid_zonemd('2018031900 1 1 abc extra', false));

        // Invalid: serial out of range
        $this->assertFalse(Dns::is_valid_zonemd('-1 1 1 abc', false));
        $this->assertFalse(Dns::is_valid_zonemd('4294967296 1 1 abc', false));

        // Invalid: scheme out of range
        $this->assertFalse(Dns::is_valid_zonemd('100 -1 1 abc', false));
        $this->assertFalse(Dns::is_valid_zonemd('100 256 1 abc', false));

        // Invalid: algorithm out of range
        $this->assertFalse(Dns::is_valid_zonemd('100 1 -1 abc', false));
        $this->assertFalse(Dns::is_valid_zonemd('100 1 256 abc', false));

        // Invalid: non-hex digest
        $this->assertFalse(Dns::is_valid_zonemd('100 1 1 gghhii', false));
    }

    public function testIsValidHTTPS()
    {
        // Valid HTTPS records (same as SVCB)
        $this->assertTrue(Dns::is_valid_https('0 foo.powerdns.org.', false));
        $this->assertTrue(Dns::is_valid_https('1 .', false));
        $this->assertTrue(Dns::is_valid_https('16 foo.example.com.', false));

        // Valid: With parameters (basic validation)
        $this->assertTrue(Dns::is_valid_https('1 foo.powerdns.org. alpn=h3,h2', false));
        $this->assertTrue(Dns::is_valid_https('16 foo.example.com. port=53', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_https('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_https('0', false));

        // Invalid: priority out of range
        $this->assertFalse(Dns::is_valid_https('-1 example.com', false));
        $this->assertFalse(Dns::is_valid_https('65536 example.com', false));

        // Invalid: bad target
        $this->assertFalse(Dns::is_valid_https('1 -invalid.com', false));
    }

    public function testIsValidSVCB()
    {
        // Valid SVCB from PowerDNS tests
        $this->assertTrue(Dns::is_valid_svcb('0 foo.powerdns.org.', false));
        $this->assertTrue(Dns::is_valid_svcb('1 .', false));
        $this->assertTrue(Dns::is_valid_svcb('16 foo.example.com.', false));

        // Valid: With parameters (basic validation)
        $this->assertTrue(Dns::is_valid_svcb('1 foo.powerdns.org. mandatory=alpn', false));
        $this->assertTrue(Dns::is_valid_svcb('1 foo.powerdns.org. no-default-alpn', false));
        $this->assertTrue(Dns::is_valid_svcb('1 foo.powerdns.org. alpn=h3,h2', false));
        $this->assertTrue(Dns::is_valid_svcb('1 foo.powerdns.org. port=53', false));

        // Invalid: empty
        $this->assertFalse(Dns::is_valid_svcb('', false));

        // Invalid: missing fields
        $this->assertFalse(Dns::is_valid_svcb('0', false));
        $this->assertFalse(Dns::is_valid_svcb('1', false));

        // Invalid: priority out of range
        $this->assertFalse(Dns::is_valid_svcb('-1 foo.example.com', false));
        $this->assertFalse(Dns::is_valid_svcb('65536 foo.example.com', false));

        // Invalid: bad target domain
        $this->assertFalse(Dns::is_valid_svcb('1 -invalid.com', false));
        $this->assertFalse(Dns::is_valid_svcb('1 invalid-.com', false));
    }
}
