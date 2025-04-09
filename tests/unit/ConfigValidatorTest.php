<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigValidator;

class ConfigValidatorTest extends TestCase
{
    public function testValidConfig(): void
    {
        $config = [
            'interface' => [
                'index_display' => 'cards',
                'rows_per_page' => 10,
                'language' => 'en_EN',
                'enabled_languages' => 'en_EN,de_DE',
            ],
            'logging' => [
                'syslog_enabled' => false,
                'syslog_identity' => 'poweradmin',
                'syslog_facility' => LOG_USER,
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertTrue($validator->validate());
        $this->assertEmpty($validator->getErrors());
    }

    public function testSyslogUseIsBoolean(): void
    {
        $config = [
            'interface' => [
                'rows_per_page' => 10,
                'language' => 'en_EN',
                'enabled_languages' => 'en_EN,de_DE',
            ],
            'logging' => [
                'syslog_enabled' => 'not_a_boolean',
                'syslog_identity' => 'poweradmin',
                'syslog_facility' => LOG_USER,
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('syslog_use', $validator->getErrors());
    }

    public function testSyslogIdentIsNotEmpty(): void
    {
        $config = [
            'interface' => [
                'rows_per_page' => 10,
                'language' => 'en_EN',
                'enabled_languages' => 'en_EN,de_DE',
            ],
            'logging' => [
                'syslog_enabled' => true,
                'syslog_identity' => '',
                'syslog_facility' => LOG_USER,
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('syslog_ident', $validator->getErrors());
    }

    public function testSyslogFacilityIsValid(): void
    {
        $config = [
            'interface' => [
                'rows_per_page' => 10,
                'language' => 'en_EN',
                'enabled_languages' => 'en_EN,de_DE',
            ],
            'logging' => [
                'syslog_enabled' => true,
                'syslog_identity' => 'poweradmin',
                'syslog_facility' => 'invalid_facility',
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('syslog_facility', $validator->getErrors());
    }

    public function testInterfaceLanguageIsNotEmpty(): void
    {
        $config = [
            'interface' => [
                'index_display' => 'cards',
                'rows_per_page' => 10,
                'language' => '',
                'enabled_languages' => 'en_EN,de_DE',
            ],
            'logging' => [
                'syslog_enabled' => false,
                'syslog_identity' => 'poweradmin',
                'syslog_facility' => LOG_USER,
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('iface_lang', $validator->getErrors());
    }

    public function testInterfaceEnabledLanguagesAreValid(): void
    {
        $config = [
            'interface' => [
                'index_display' => 'cards',
                'rows_per_page' => 10,
                'language' => 'en_EN',
                'enabled_languages' => 'en_EN,de_DE',
            ],
            'logging' => [
                'syslog_enabled' => false,
                'syslog_identity' => 'poweradmin',
                'syslog_facility' => LOG_USER,
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertTrue($validator->validate());
        $this->assertEmpty($validator->getErrors());
    }

    public function testInterfaceEnabledLanguagesAreNotEmpty(): void
    {
        $config = [
            'interface' => [
                'index_display' => 'cards',
                'rows_per_page' => 10,
                'language' => 'en_EN',
                'enabled_languages' => '',
            ],
            'logging' => [
                'syslog_enabled' => false,
                'syslog_identity' => 'poweradmin',
                'syslog_facility' => LOG_USER,
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('iface_enabled_languages', $validator->getErrors());
    }

    public function testInterfaceEnabledLanguagesDoNotContainEmptyItems(): void
    {
        $config = [
            'interface' => [
                'index_display' => 'cards',
                'rows_per_page' => 10,
                'language' => 'en_EN',
                'enabled_languages' => 'en_EN,de_DE,',
            ],
            'logging' => [
                'syslog_enabled' => false,
                'syslog_identity' => 'poweradmin',
                'syslog_facility' => LOG_USER,
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('iface_enabled_languages', $validator->getErrors());
    }

    public function testInterfaceLanguageIsIncludedInEnabledLanguages(): void
    {
        $config = [
            'interface' => [
                'index_display' => 'cards',
                'rows_per_page' => 10,
                'language' => 'fr_FR',
                'enabled_languages' => 'en_EN,de_DE',
            ],
            'logging' => [
                'syslog_enabled' => false,
                'syslog_identity' => 'poweradmin',
                'syslog_facility' => LOG_USER,
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('iface_lang', $validator->getErrors());
    }
}
