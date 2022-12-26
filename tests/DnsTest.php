<?php

use Poweradmin\Dns;
use PHPUnit\Framework\TestCase;
class DnsTest extends TestCase
{

    public function testIs_valid_ds()
    {
        $this->assertTrue(Dns::is_valid_ds("2371 13 2 1F987CC6583E92DF0890718C42"));
        $this->assertTrue(Dns::is_valid_ds("15288 5 2 CE0EB9E59EE1DE2C681A330E3A7C08376F28602CDF990EE4EC88D2A8BDB51539"));

        $this->assertFalse(Dns::is_valid_ds("2371 13 2 1F987CC6583E92DF0890718C42 ; ( SHA1 digest )"));
        $this->assertFalse(Dns::is_valid_ds("invalid"));
    }
}
