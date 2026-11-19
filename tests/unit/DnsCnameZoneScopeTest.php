<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * The CNAME checks only look at the zone the record is written to. The fake database
 * holds one conflicting row in zone 2 and answers a query only when it reaches that zone.
 */
class DnsCnameZoneScopeTest extends TestCase
{
    private const OTHER_ZONE = 2;
    private const THIS_ZONE = 1;

    private Dns $dns;

    protected function setUp(): void
    {
        ob_start();
        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(fn($value, $type = null) => $type === 'integer' ? (string)$value : "'" . $value . "'");
        $db->method('queryOne')->willReturnCallback(function (string $query) {
            if (!str_contains($query, 'domain_id')) {
                return ['id' => 99];
            }
            return str_contains($query, 'domain_id = ' . self::OTHER_ZONE) ? ['id' => 99] : null;
        });

        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturn('');
        $this->dns = new Dns($db, $config);
    }

    public function testMxInAnotherZoneDoesNotBlockTheCname(): void
    {
        $this->assertTrue($this->dns->is_valid_rr_cname_name('www.victim.example', self::THIS_ZONE));
        $this->assertFalse($this->dns->is_valid_rr_cname_name('www.victim.example', self::OTHER_ZONE));
        $this->assertFalse($this->dns->is_valid_rr_cname_name('www.victim.example'), 'No zone keeps the old lookup');
    }

    public function testRecordInAnotherZoneDoesNotBlockTheCname(): void
    {
        $this->assertTrue($this->dns->is_valid_rr_cname_unique('www.victim.example', '-1', self::THIS_ZONE));
        $this->assertFalse($this->dns->is_valid_rr_cname_unique('www.victim.example', '-1', self::OTHER_ZONE));
    }

    public function testCnameInAnotherZoneDoesNotBlockARecordWithTheSameName(): void
    {
        $this->assertTrue($this->dns->is_valid_rr_cname_exists('www.victim.example', -1, self::THIS_ZONE));
        $this->assertFalse($this->dns->is_valid_rr_cname_exists('www.victim.example', -1, self::OTHER_ZONE));
    }

    public function testCnameInAnotherZoneDoesNotBlockAnMxTarget(): void
    {
        $this->assertTrue($this->dns->is_valid_non_alias_target('mail.victim.example', self::THIS_ZONE));
        $this->assertFalse($this->dns->is_valid_non_alias_target('mail.victim.example', self::OTHER_ZONE));
    }

    protected function tearDown(): void
    {
        ob_end_clean();
    }
}
