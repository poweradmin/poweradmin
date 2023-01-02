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

    public function testIsValidLocation() {
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
//		$this->assertFalse(is_valid_loc('92 21 28.764 N 71 00 51.617 W -44m 2000m'));
        # alt maxes to 42849672.95
        $this->assertFalse(Dns::is_valid_loc('90 59 59.9 N 10 18 E 42849672.96m 1m'));

        # alt maxes to -100000.00
        $this->assertFalse(Dns::is_valid_loc('9 10 S 12 22 33.4 E -110000.00m 2m 34 3m'));
    }

}
