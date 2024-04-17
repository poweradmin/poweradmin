<?php

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigValidator;

class ConfigValidatorTest extends TestCase
{
    public function testValidConfig(): void
    {
        $config = [
            'iface_index' => 'cards',
            'iface_rowamount' => 10,
            'syslog_use' => false,
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
        ];

        $validator = new ConfigValidator($config);

        $this->assertTrue($validator->validate());
        $this->assertEmpty($validator->getErrors());
    }

    public function testInvalidSyslogUse(): void
    {
        $config = [
            'iface_rowamount' => 10,
            'syslog_use' => 'not_a_boolean',
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('syslog_use', $validator->getErrors());
    }

    public function testInvalidSyslogIdent(): void
    {
        $config = [
            'iface_rowamount' => 10,
            'syslog_use' => true,
            'syslog_ident' => '',
            'syslog_facility' => LOG_USER,
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('syslog_ident', $validator->getErrors());
    }

    public function testInvalidSyslogFacility(): void
    {
        $config = [
            'iface_rowamount' => 10,
            'syslog_use' => true,
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => 'invalid_facility',
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('syslog_facility', $validator->getErrors());
    }
}
