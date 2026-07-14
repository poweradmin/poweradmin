<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Utility\DnsHelper;

class DnsHelperTest extends TestCase
{
    public function testIsReverseZonePositiveCases(): void
    {
        $this->assertTrue(DnsHelper::isReverseZone('1.0.0.127.in-addr.arpa'), 'Should return true for IPv4 reverse zone.');
        $this->assertTrue(DnsHelper::isReverseZone('160/27.236.20.172.in-addr.arpa'), 'Should return true for IPv4 reverse zone with CIDR notation.');
        $this->assertTrue(DnsHelper::isReverseZone('0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa'), 'Should return true for IPv6 reverse zone.');
        $this->assertTrue(DnsHelper::isReverseZone('1/48.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa'), 'Should return true for IPv6 reverse zone with CIDR notation.');
    }

    public function testIsReverseZoneNegativeCases(): void
    {
        $this->assertFalse(DnsHelper::isReverseZone('example.com'), 'Should return false for a regular domain.');
        $this->assertFalse(DnsHelper::isReverseZone('subdomain.example.com'), 'Should return false for a subdomain.');
        $this->assertFalse(DnsHelper::isReverseZone('example.in-addr.arpa.com'), 'Should return false for a domain containing in-addr.arpa but not a reverse zone.');
    }

    public function testIsReverseZoneCornerCases(): void
    {
        $this->assertFalse(DnsHelper::isReverseZone(''), 'Should return false for an empty string.');
        $this->assertFalse(DnsHelper::isReverseZone(' '), 'Should return false for a string with only whitespace.');
        $this->assertFalse(DnsHelper::isReverseZone('in-addr.arpa'), 'Should return false for a string with only in-addr.arpa.');
        $this->assertFalse(DnsHelper::isReverseZone('ip6.arpa'), 'Should return false for a string with only ip6.arpa.');
        $this->assertFalse(DnsHelper::isReverseZone('1.0.0.127.in-addr.arpa '), 'Should return false for a reverse zone with trailing whitespace.');
        $this->assertFalse(DnsHelper::isReverseZone(' 1.0.0.127.in-addr.arpa'), 'Should return false for a reverse zone with leading whitespace.');
    }

    public function testIsReverseZoneNamePositiveCases(): void
    {
        $this->assertTrue(DnsHelper::isReverseZoneName('1.0.0.127.in-addr.arpa'), 'IPv4 reverse zone is in the reverse namespace.');
        $this->assertTrue(DnsHelper::isReverseZoneName('0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa'), 'IPv6 reverse zone is in the reverse namespace.');
        $this->assertTrue(DnsHelper::isReverseZoneName('in-addr.arpa'), 'The in-addr.arpa apex is in the reverse namespace.');
        $this->assertTrue(DnsHelper::isReverseZoneName('ip6.arpa'), 'The ip6.arpa apex is in the reverse namespace.');
        $this->assertTrue(DnsHelper::isReverseZoneName('1.0.0.127.IN-ADDR.ARPA'), 'Classification is case-insensitive.');
        $this->assertTrue(DnsHelper::isReverseZoneName('1.0.0.127.in-addr.arpa.'), 'A trailing dot does not change classification.');
    }

    public function testIsReverseZoneNameNegativeCases(): void
    {
        $this->assertFalse(DnsHelper::isReverseZoneName('example.com'), 'A regular domain is not reverse.');
        $this->assertFalse(DnsHelper::isReverseZoneName('example.in-addr.arpa.com'), 'A name only containing in-addr.arpa as an infix is not reverse.');
        $this->assertFalse(DnsHelper::isReverseZoneName(''), 'An empty string is not reverse.');
    }

    /**
     * RFC 2317 classless names carry no parsable network, so the strict
     * isReverseZone() rejects them, but they still belong to the reverse
     * namespace and must be classified as reverse for listing, the overlap
     * guard, the post-create redirect and the record UI. This pins that
     * divergence.
     */
    public function testIsReverseZoneNameAcceptsRfc2317ClasslessNames(): void
    {
        $classless = '0-25.2.0.192.in-addr.arpa';

        $this->assertFalse(DnsHelper::isReverseZone($classless), 'Strict check rejects the classless name.');
        $this->assertTrue(DnsHelper::isReverseZoneName($classless), 'Namespace check accepts the classless name.');
    }

    public function testGetRegisteredDomainWithSimpleDomain()
    {
        $this->assertEquals('example.com', DnsHelper::getRegisteredDomain('example.com'));
    }

    public function testGetRegisteredDomainWithSubdomain()
    {
        $this->assertEquals('example.com', DnsHelper::getRegisteredDomain('sub.example.com'));
    }

    public function testGetRegisteredDomainWithMultipleSubdomains()
    {
        $this->assertEquals('example.com', DnsHelper::getRegisteredDomain('sub.sub2.example.com'));
    }

    public function testGetRegisteredDomainWithCountryCodeTLD()
    {
        $this->assertEquals('example.co.uk', DnsHelper::getRegisteredDomain('sub.example.co.uk'));
    }

//    public function testGetRegisteredDomainWithSinglePartDomain()
//    {
//        $this->assertEquals('localhost', DnsHelper::getRegisteredDomain('localhost'));
//    }

    public function testGetDomainNameWithSubdomain()
    {
        $this->assertEquals('sub', DnsHelper::getSubDomainName('sub.example.com'));
    }

    public function testGetDomainNameWithoutSubdomain()
    {
        $this->assertEquals('example.com', DnsHelper::getSubDomainName('example.com'));
    }

    public function testGetDomainNameWithMultipleSubdomains()
    {
        $this->assertEquals('sub.sub', DnsHelper::getSubDomainName('sub.sub.example.com'));
    }

    public function testGetDomainNameWithSinglePartDomain()
    {
        $this->assertEquals('localhost', DnsHelper::getSubDomainName('localhost'));
    }

    public function testGetDomainNameWithTwoPartDomain()
    {
        $this->assertEquals('example', DnsHelper::getSubDomainName('example.co.uk'));
    }

    // Tests for isWithinZone

    public function testIsWithinZoneMatchesApexAndSubdomains(): void
    {
        $this->assertTrue(DnsHelper::isWithinZone('example.com', 'example.com'), 'The zone apex is within the zone.');
        $this->assertTrue(DnsHelper::isWithinZone('www.example.com', 'example.com'), 'A direct subdomain is within the zone.');
        $this->assertTrue(DnsHelper::isWithinZone('a.b.example.com', 'example.com'), 'A deep subdomain is within the zone.');
    }

    public function testIsWithinZoneRejectsOutOfZoneNames(): void
    {
        $this->assertFalse(DnsHelper::isWithinZone('testexample.com', 'example.com'), 'A label-boundary substring is not within the zone.');
        $this->assertFalse(DnsHelper::isWithinZone('example.com', 'www.example.com'), 'A parent is not within a child zone.');
        $this->assertFalse(DnsHelper::isWithinZone('example.org', 'example.com'), 'A different zone is not a match.');
        $this->assertFalse(DnsHelper::isWithinZone('example.com.evil.com', 'example.com'), 'A suffix in the middle is not a match.');
    }

    public function testIsWithinZoneIsCaseInsensitive(): void
    {
        $this->assertTrue(DnsHelper::isWithinZone('WWW.Example.COM', 'example.com'), 'Comparison is case-insensitive.');
        $this->assertTrue(DnsHelper::isWithinZone('example.com', 'EXAMPLE.COM'), 'The zone name comparison is case-insensitive.');
    }

    public function testIsWithinZoneComparesNamesAsGiven(): void
    {
        // Trailing dots are not normalized here; callers pass already-normalized names.
        $this->assertFalse(DnsHelper::isWithinZone('www.example.com.', 'example.com'), 'A trailing dot on the name is not trimmed, so it does not match.');
        $this->assertFalse(DnsHelper::isWithinZone('www.example.com', 'example.com.'), 'A trailing dot on the zone is not trimmed, so it does not match.');
    }

    // Tests for stripZoneSuffix

    public function testStripZoneSuffixWithZoneApex(): void
    {
        $this->assertEquals('@', DnsHelper::stripZoneSuffix('example.com', 'example.com'));
    }

    public function testStripZoneSuffixWithZoneApexCaseInsensitive(): void
    {
        $this->assertEquals('@', DnsHelper::stripZoneSuffix('EXAMPLE.COM', 'example.com'));
        $this->assertEquals('@', DnsHelper::stripZoneSuffix('example.com', 'EXAMPLE.COM'));
        $this->assertEquals('@', DnsHelper::stripZoneSuffix('ExAmPlE.CoM', 'example.com'));
    }

    public function testStripZoneSuffixWithZoneApexTrailingDot(): void
    {
        $this->assertEquals('@', DnsHelper::stripZoneSuffix('example.com.', 'example.com'));
        $this->assertEquals('@', DnsHelper::stripZoneSuffix('example.com', 'example.com.'));
        $this->assertEquals('@', DnsHelper::stripZoneSuffix('example.com.', 'example.com.'));
    }

    public function testStripZoneSuffixWithSubdomain(): void
    {
        $this->assertEquals('www', DnsHelper::stripZoneSuffix('www.example.com', 'example.com'));
        $this->assertEquals('mail', DnsHelper::stripZoneSuffix('mail.example.com', 'example.com'));
    }

    public function testStripZoneSuffixWithSubdomainCaseInsensitive(): void
    {
        $this->assertEquals('WWW', DnsHelper::stripZoneSuffix('WWW.EXAMPLE.COM', 'example.com'));
        $this->assertEquals('www', DnsHelper::stripZoneSuffix('www.example.com', 'EXAMPLE.COM'));
        $this->assertEquals('WwW', DnsHelper::stripZoneSuffix('WwW.ExAmPlE.CoM', 'example.com'));
    }

    public function testStripZoneSuffixWithSubdomainTrailingDot(): void
    {
        $this->assertEquals('www', DnsHelper::stripZoneSuffix('www.example.com.', 'example.com'));
        $this->assertEquals('www', DnsHelper::stripZoneSuffix('www.example.com', 'example.com.'));
        $this->assertEquals('www', DnsHelper::stripZoneSuffix('www.example.com.', 'example.com.'));
    }

    public function testStripZoneSuffixWithMultiLevelSubdomain(): void
    {
        $this->assertEquals('sub.www', DnsHelper::stripZoneSuffix('sub.www.example.com', 'example.com'));
        $this->assertEquals('a.b.c', DnsHelper::stripZoneSuffix('a.b.c.example.com', 'example.com'));
    }

    public function testStripZoneSuffixWithDifferentZone(): void
    {
        // When record name doesn't end with zone, return as-is
        $this->assertEquals('www.other.com', DnsHelper::stripZoneSuffix('www.other.com', 'example.com'));
    }

    public function testStripZoneSuffixWithReverseZone(): void
    {
        $this->assertEquals('10', DnsHelper::stripZoneSuffix('10.1.0.168.192.in-addr.arpa', '1.0.168.192.in-addr.arpa'));
        $this->assertEquals('@', DnsHelper::stripZoneSuffix('1.0.168.192.in-addr.arpa', '1.0.168.192.in-addr.arpa'));
    }

    // Tests for restoreZoneSuffix

    public function testRestoreZoneSuffixWithAtSymbol(): void
    {
        $this->assertEquals('example.com', DnsHelper::restoreZoneSuffix('@', 'example.com'));
    }

    public function testRestoreZoneSuffixWithEmptyString(): void
    {
        $this->assertEquals('example.com', DnsHelper::restoreZoneSuffix('', 'example.com'));
    }

    public function testRestoreZoneSuffixWithRelativeHostname(): void
    {
        $this->assertEquals('www.example.com', DnsHelper::restoreZoneSuffix('www', 'example.com'));
        $this->assertEquals('mail.example.com', DnsHelper::restoreZoneSuffix('mail', 'example.com'));
    }

    public function testRestoreZoneSuffixWithMultiLevelRelativeHostname(): void
    {
        $this->assertEquals('sub.www.example.com', DnsHelper::restoreZoneSuffix('sub.www', 'example.com'));
        $this->assertEquals('a.b.c.example.com', DnsHelper::restoreZoneSuffix('a.b.c', 'example.com'));
    }

    public function testRestoreZoneSuffixWithAlreadyQualifiedName(): void
    {
        // If hostname already contains zone name, return as-is
        $this->assertEquals('www.example.com', DnsHelper::restoreZoneSuffix('www.example.com', 'example.com'));
        $this->assertEquals('example.com', DnsHelper::restoreZoneSuffix('example.com', 'example.com'));
    }

    public function testRestoreZoneSuffixWithAlreadyQualifiedNameCaseInsensitive(): void
    {
        // Case-insensitive detection prevents duplicate suffixes
        $this->assertEquals('WWW.EXAMPLE.COM', DnsHelper::restoreZoneSuffix('WWW.EXAMPLE.COM', 'example.com'));
        $this->assertEquals('www.example.com', DnsHelper::restoreZoneSuffix('www.example.com', 'EXAMPLE.COM'));
        $this->assertEquals('WwW.ExAmPlE.CoM', DnsHelper::restoreZoneSuffix('WwW.ExAmPlE.CoM', 'example.com'));
    }

    public function testRestoreZoneSuffixWithTrailingDot(): void
    {
        // Trailing dots should be stripped before processing
        $this->assertEquals('www.example.com', DnsHelper::restoreZoneSuffix('www.', 'example.com'));
        $this->assertEquals('www.example.com', DnsHelper::restoreZoneSuffix('www', 'example.com.'));
        $this->assertEquals('www.example.com', DnsHelper::restoreZoneSuffix('www.', 'example.com.'));
    }

    public function testRestoreZoneSuffixWithTrailingDotAlreadyQualified(): void
    {
        // Already qualified names with trailing dots shouldn't get duplicated
        $this->assertEquals('www.example.com', DnsHelper::restoreZoneSuffix('www.example.com.', 'example.com'));
        $this->assertEquals('example.com', DnsHelper::restoreZoneSuffix('example.com.', 'example.com'));
    }

    public function testRestoreZoneSuffixWithReverseZone(): void
    {
        $this->assertEquals('10.1.0.168.192.in-addr.arpa', DnsHelper::restoreZoneSuffix('10', '1.0.168.192.in-addr.arpa'));
        $this->assertEquals('1.0.168.192.in-addr.arpa', DnsHelper::restoreZoneSuffix('@', '1.0.168.192.in-addr.arpa'));
    }

    // Round-trip tests (strip then restore should give original FQDN)

    public function testStripAndRestoreZoneSuffixRoundTrip(): void
    {
        $fqdn = 'www.example.com';
        $zone = 'example.com';
        $stripped = DnsHelper::stripZoneSuffix($fqdn, $zone);
        $restored = DnsHelper::restoreZoneSuffix($stripped, $zone);
        $this->assertEquals($fqdn, $restored);
    }

    public function testStripAndRestoreZoneSuffixRoundTripWithZoneApex(): void
    {
        $fqdn = 'example.com';
        $zone = 'example.com';
        $stripped = DnsHelper::stripZoneSuffix($fqdn, $zone);
        $this->assertEquals('@', $stripped);
        $restored = DnsHelper::restoreZoneSuffix($stripped, $zone);
        $this->assertEquals($fqdn, $restored);
    }

    public function testStripAndRestoreZoneSuffixRoundTripCaseInsensitive(): void
    {
        $fqdn = 'WWW.EXAMPLE.COM';
        $zone = 'example.com';
        $stripped = DnsHelper::stripZoneSuffix($fqdn, $zone);
        $this->assertEquals('WWW', $stripped); // Preserves hostname casing
        $restored = DnsHelper::restoreZoneSuffix($stripped, $zone);
        // Restored name uses zone's casing, not original FQDN casing
        $this->assertEquals('WWW.example.com', $restored);
    }

    public function testStripAndRestoreZoneSuffixRoundTripWithTrailingDot(): void
    {
        $fqdn = 'www.example.com.';
        $zone = 'example.com';
        $stripped = DnsHelper::stripZoneSuffix($fqdn, $zone);
        $this->assertEquals('www', $stripped);
        $restored = DnsHelper::restoreZoneSuffix($stripped, $zone);
        $this->assertEquals('www.example.com', $restored); // Trailing dot removed
    }

    public function testResolveReverseZoneNamePassesThroughReverseNames(): void
    {
        // Already-valid reverse zone names are returned unchanged
        $this->assertEquals('1.168.192.in-addr.arpa', DnsHelper::resolveReverseZoneName('1.168.192.in-addr.arpa'));
        $this->assertEquals('8.b.d.0.1.0.0.2.ip6.arpa', DnsHelper::resolveReverseZoneName('8.b.d.0.1.0.0.2.ip6.arpa'));
    }

    public function testResolveReverseZoneNameConvertsNetworks(): void
    {
        $this->assertEquals('1.168.192.in-addr.arpa', DnsHelper::resolveReverseZoneName('192.168.1.0/24'));
        $this->assertEquals('0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', DnsHelper::resolveReverseZoneName('2001:db8::/48'));
    }

    public function testResolveReverseZoneNameRejectsForwardNames(): void
    {
        // Non-reverse, non-network input returns null so the caller can reject it
        $this->assertNull(DnsHelper::resolveReverseZoneName('example.com'));
        $this->assertNull(DnsHelper::resolveReverseZoneName('myreversezone'));
        // Names isReverseZone() does not recognize are rejected for consistency
        // with the post-create redirect (RFC 2317 range-style, malformed labels)
        $this->assertNull(DnsHelper::resolveReverseZoneName('0-63.1.168.192.in-addr.arpa'));
        $this->assertNull(DnsHelper::resolveReverseZoneName('abc.in-addr.arpa'));
    }

    public function testIsZoneApexPositiveCases(): void
    {
        $this->assertTrue(DnsHelper::isZoneApex('example.com', 'example.com'));
        $this->assertTrue(DnsHelper::isZoneApex('EXAMPLE.COM', 'example.com'), 'Comparison is case-insensitive.');
        $this->assertTrue(DnsHelper::isZoneApex('example.com.', 'example.com'), 'A trailing dot on the record name is ignored.');
        $this->assertTrue(DnsHelper::isZoneApex('example.com', 'example.com.'), 'A trailing dot on the zone name is ignored.');
    }

    public function testIsZoneApexNegativeCases(): void
    {
        $this->assertFalse(DnsHelper::isZoneApex('sub.example.com', 'example.com'));
        $this->assertFalse(DnsHelper::isZoneApex('example.com', 'sub.example.com'));
        $this->assertFalse(DnsHelper::isZoneApex('other.org', 'example.com'));
        $this->assertFalse(DnsHelper::isZoneApex('testexample.com', 'example.com'), 'A suffix match without a dot boundary is not the apex.');
    }
}
