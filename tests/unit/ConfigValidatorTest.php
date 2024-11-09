<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigValidator;

class ConfigValidatorTest extends TestCase
{
    public function testValidConfig(): void
    {
        $config = [
            'iface_index' => 'cards',
            'iface_rowamount' => 10,
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN,de_DE',
            'syslog_use' => false,
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
        ];

        $validator = new ConfigValidator($config);

        $this->assertTrue($validator->validate());
        $this->assertEmpty($validator->getErrors());
    }

    public function testSyslogUseIsBoolean(): void
    {
        $config = [
            'iface_rowamount' => 10,
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN,de_DE',
            'syslog_use' => 'not_a_boolean',
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('syslog_use', $validator->getErrors());
    }

    public function testSyslogIdentIsNotEmpty(): void
    {
        $config = [
            'iface_rowamount' => 10,
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN,de_DE',
            'syslog_use' => true,
            'syslog_ident' => '',
            'syslog_facility' => LOG_USER,
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('syslog_ident', $validator->getErrors());
    }

    public function testSyslogFacilityIsValid(): void
    {
        $config = [
            'iface_rowamount' => 10,
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN,de_DE',
            'syslog_use' => true,
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => 'invalid_facility',
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('syslog_facility', $validator->getErrors());
    }

    public function testInterfaceLanguageIsNotEmpty(): void
    {
        $config = [
            'iface_index' => 'cards',
            'iface_rowamount' => 10,
            'syslog_use' => false,
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
            'iface_lang' => '',
            'iface_enabled_languages' => 'en_EN,de_DE',
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('iface_lang', $validator->getErrors());
    }

    public function testInterfaceEnabledLanguagesAreValid(): void
    {
        $config = [
            'iface_index' => 'cards',
            'iface_rowamount' => 10,
            'syslog_use' => false,
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN,de_DE',
        ];

        $validator = new ConfigValidator($config);

        $this->assertTrue($validator->validate());
        $this->assertEmpty($validator->getErrors());
    }

    public function testInterfaceEnabledLanguagesAreNotEmpty(): void
    {
        $config = [
            'iface_index' => 'cards',
            'iface_rowamount' => 10,
            'syslog_use' => false,
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => '',
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('iface_lang', $validator->getErrors());
    }

    public function testInterfaceEnabledLanguagesDoNotContainEmptyItems(): void
    {
        $config = [
            'iface_index' => 'cards',
            'iface_rowamount' => 10,
            'syslog_use' => false,
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN,de_DE,',
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('iface_enabled_languages', $validator->getErrors());
    }

    public function testInterfaceLanguageIsIncludedInEnabledLanguages(): void
    {
        $config = [
            'iface_index' => 'cards',
            'iface_rowamount' => 10,
            'syslog_use' => false,
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
            'iface_lang' => 'fr_FR',
            'iface_enabled_languages' => 'en_EN,de_DE',
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('iface_lang', $validator->getErrors());
    }
}
