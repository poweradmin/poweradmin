<?php

namespace unit\Dns;

/**
 * Tests for CNAME record validation
 */
class CnameValidationTest extends BaseDnsTest
{
    public function testIsValidRrCnameName()
    {
        // Valid CNAME name (no MX/NS records exist that point to it)
        $name = 'valid.cname.example.com';
        $result = $this->dnsInstance->is_valid_rr_cname_name($name);
        $this->assertTrue($result);

        // Invalid CNAME name (MX/NS record points to it)
        $name = 'invalid.cname.target';
        $result = $this->dnsInstance->is_valid_rr_cname_name($name);
        $this->assertFalse($result);
    }

    public function testIsValidRrCnameExists()
    {
        // Valid case - no existing CNAME record with this name
        $name = 'new.example.com';
        $rid = 0;
        $result = $this->dnsInstance->is_valid_rr_cname_exists($name, $rid);
        $this->assertTrue($result);

        // Valid case - checking against a specific record ID
        $name = 'new.example.com';
        $rid = 123;
        $result = $this->dnsInstance->is_valid_rr_cname_exists($name, $rid);
        $this->assertTrue($result);

        // Invalid case - CNAME record already exists with this name
        $name = 'existing.cname.example.com';
        $rid = 0;
        $result = $this->dnsInstance->is_valid_rr_cname_exists($name, $rid);
        $this->assertFalse($result);
    }

    public function testIsValidRrCnameUnique()
    {
        // Valid case - no existing record with this name
        $name = 'new.example.com';
        $rid = 0;
        $result = $this->dnsInstance->is_valid_rr_cname_unique($name, $rid);
        $this->assertTrue($result);

        // Valid case - checking against a specific record ID
        $name = 'new.example.com';
        $rid = 123;
        $result = $this->dnsInstance->is_valid_rr_cname_unique($name, $rid);
        $this->assertTrue($result);
    }

    public function testIsValidNonAliasTarget()
    {
        // Valid case - target is not a CNAME
        $target = 'valid.example.com';
        $result = $this->dnsInstance->is_valid_non_alias_target($target);
        $this->assertTrue($result);

        // Invalid case - target is a CNAME
        $target = 'alias.example.com';
        $result = $this->dnsInstance->is_valid_non_alias_target($target);
        $this->assertFalse($result);
    }

    public function testIsNotEmptyCnameRR()
    {
        // Valid non-empty CNAME
        $this->assertTrue($this->dnsInstance->is_not_empty_cname_rr('subdomain.example.com', 'example.com'));
        $this->assertTrue($this->dnsInstance->is_not_empty_cname_rr('www.example.com', 'example.com'));

        // Invalid empty CNAME (name equals zone)
        $this->assertFalse($this->dnsInstance->is_not_empty_cname_rr('example.com', 'example.com'));
    }
}
