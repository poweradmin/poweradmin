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
        $this->assertArrayHasKey('logging.syslog_enabled', $validator->getErrors());
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
        $this->assertArrayHasKey('logging.syslog_identity', $validator->getErrors());
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
        $this->assertArrayHasKey('logging.syslog_facility', $validator->getErrors());
    }

    public function testInterfaceLanguageIsNotEmpty(): void
    {
        $config = [
            'interface' => [
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
        $this->assertArrayHasKey('interface.language', $validator->getErrors());
    }

    public function testInterfaceEnabledLanguagesAreValid(): void
    {
        $config = [
            'interface' => [
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
        $this->assertArrayHasKey('interface.enabled_languages', $validator->getErrors());
    }

    public function testInterfaceEnabledLanguagesDoNotContainEmptyItems(): void
    {
        $config = [
            'interface' => [
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
        $this->assertArrayHasKey('interface.enabled_languages', $validator->getErrors());
    }

    public function testInterfaceLanguageIsIncludedInEnabledLanguages(): void
    {
        $config = [
            'interface' => [
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
        $this->assertArrayHasKey('interface.language', $validator->getErrors());
    }

    public function testInterfaceRowsPerPageIsInteger(): void
    {
        $config = [
            'interface' => [
                'rows_per_page' => 'not_an_integer',
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

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('interface.rows_per_page', $validator->getErrors());
    }

    public function testInterfaceRowsPerPageIsPositive(): void
    {
        $config = [
            'interface' => [
                'rows_per_page' => -5,
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

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('interface.rows_per_page', $validator->getErrors());
    }

    public function testInterfaceRowsPerPageZeroIsInvalid(): void
    {
        $config = [
            'interface' => [
                'rows_per_page' => 0,
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

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('interface.rows_per_page', $validator->getErrors());
    }

    public function testInterfaceRowsPerPagePositiveIntegerIsValid(): void
    {
        $config = [
            'interface' => [
                'rows_per_page' => 25,
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

    public function testSyslogEnabledNonBooleanTypes(): void
    {
        $invalidValues = [
            'string_true' => 'true',
            'string_false' => 'false',
            'integer_1' => 1,
            'integer_0' => 0,
            'null' => null,
            'array' => [],
        ];

        foreach ($invalidValues as $testName => $value) {
            $config = [
                'interface' => [
                    'rows_per_page' => 10,
                    'language' => 'en_EN',
                    'enabled_languages' => 'en_EN,de_DE',
                ],
                'logging' => [
                    'syslog_enabled' => $value,
                    'syslog_identity' => 'poweradmin',
                    'syslog_facility' => LOG_USER,
                ],
            ];

            $validator = new ConfigValidator($config);

            $this->assertFalse($validator->validate(), "Failed for test case: $testName");
            $this->assertArrayHasKey('logging.syslog_enabled', $validator->getErrors(), "Failed for test case: $testName");
        }
    }

    public function testSyslogEnabledValidBooleanTypes(): void
    {
        $validValues = [true, false];

        foreach ($validValues as $value) {
            $config = [
                'interface' => [
                    'rows_per_page' => 10,
                    'language' => 'en_EN',
                    'enabled_languages' => 'en_EN,de_DE',
                ],
                'logging' => [
                    'syslog_enabled' => $value,
                    'syslog_identity' => 'poweradmin',
                    'syslog_facility' => LOG_USER,
                ],
            ];

            $validator = new ConfigValidator($config);

            if ($value) {
                // When syslog is enabled, it should validate identity and facility
                $this->assertTrue($validator->validate(), "Failed for boolean value: " . var_export($value, true));
            } else {
                // When syslog is disabled, identity and facility are not validated
                $this->assertTrue($validator->validate(), "Failed for boolean value: " . var_export($value, true));
            }
        }
    }

    public function testSyslogFacilityValidValues(): void
    {
        $validFacilities = [
            LOG_USER,
            LOG_LOCAL0,
            LOG_LOCAL1,
            LOG_LOCAL2,
            LOG_LOCAL3,
            LOG_LOCAL4,
            LOG_LOCAL5,
            LOG_LOCAL6,
            LOG_LOCAL7,
        ];

        foreach ($validFacilities as $facility) {
            $config = [
                'interface' => [
                    'rows_per_page' => 10,
                    'language' => 'en_EN',
                    'enabled_languages' => 'en_EN,de_DE',
                ],
                'logging' => [
                    'syslog_enabled' => true,
                    'syslog_identity' => 'poweradmin',
                    'syslog_facility' => $facility,
                ],
            ];

            $validator = new ConfigValidator($config);

            $this->assertTrue($validator->validate(), "Failed for facility: $facility");
            $this->assertEmpty($validator->getErrors(), "Failed for facility: $facility");
        }
    }

    public function testSyslogFacilityInvalidValues(): void
    {
        $invalidFacilities = [
            999,           // Invalid facility number
            'LOG_INVALID', // Invalid string
            'not_a_facility',
            null,
            [],
        ];

        foreach ($invalidFacilities as $facility) {
            $config = [
                'interface' => [
                    'rows_per_page' => 10,
                    'language' => 'en_EN',
                    'enabled_languages' => 'en_EN,de_DE',
                ],
                'logging' => [
                    'syslog_enabled' => true,
                    'syslog_identity' => 'poweradmin',
                    'syslog_facility' => $facility,
                ],
            ];

            $validator = new ConfigValidator($config);

            $this->assertFalse($validator->validate(), "Failed for invalid facility: " . var_export($facility, true));
            $this->assertArrayHasKey('logging.syslog_facility', $validator->getErrors(), "Failed for invalid facility: " . var_export($facility, true));
        }
    }

    public function testInterfaceLanguageValidFormats(): void
    {
        $validLanguages = ['en_EN', 'de_DE', 'fr_FR', 'es_ES', 'zh_CN'];

        foreach ($validLanguages as $language) {
            $config = [
                'interface' => [
                    'rows_per_page' => 10,
                    'language' => $language,
                    'enabled_languages' => "$language,en_EN,de_DE",
                ],
                'logging' => [
                    'syslog_enabled' => false,
                    'syslog_identity' => 'poweradmin',
                    'syslog_facility' => LOG_USER,
                ],
            ];

            $validator = new ConfigValidator($config);

            $this->assertTrue($validator->validate(), "Failed for language: $language");
            $this->assertEmpty($validator->getErrors(), "Failed for language: $language");
        }
    }

    public function testInterfaceLanguageInvalidTypes(): void
    {
        $invalidLanguages = [
            null,
            123,
            [],
            false,
            '',
        ];

        foreach ($invalidLanguages as $language) {
            $config = [
                'interface' => [
                    'rows_per_page' => 10,
                    'language' => $language,
                    'enabled_languages' => 'en_EN,de_DE',
                ],
                'logging' => [
                    'syslog_enabled' => false,
                    'syslog_identity' => 'poweradmin',
                    'syslog_facility' => LOG_USER,
                ],
            ];

            $validator = new ConfigValidator($config);

            $this->assertFalse($validator->validate(), "Failed for invalid language: " . var_export($language, true));
            $this->assertArrayHasKey('interface.language', $validator->getErrors(), "Failed for invalid language: " . var_export($language, true));
        }
    }

    public function testInterfaceEnabledLanguagesEdgeCases(): void
    {
        $edgeCases = [
            'trailing_comma' => 'en_EN,de_DE,',
            'leading_comma' => ',en_EN,de_DE',
            'double_comma' => 'en_EN,,de_DE',
        ];

        foreach ($edgeCases as $testCase => $languages) {
            $config = [
                'interface' => [
                    'rows_per_page' => 10,
                    'language' => 'en_EN',
                    'enabled_languages' => $languages,
                ],
                'logging' => [
                    'syslog_enabled' => false,
                    'syslog_identity' => 'poweradmin',
                    'syslog_facility' => LOG_USER,
                ],
            ];

            $validator = new ConfigValidator($config);

            $this->assertFalse($validator->validate(), "Failed for edge case: $testCase");
            $this->assertArrayHasKey('interface.enabled_languages', $validator->getErrors(), "Failed for edge case: $testCase");
        }
    }

    public function testInterfaceEnabledLanguagesWithSpacesIsValid(): void
    {
        // Spaces around languages should be trimmed and considered valid
        $config = [
            'interface' => [
                'rows_per_page' => 10,
                'language' => 'en_EN',
                'enabled_languages' => ' en_EN , de_DE ',
            ],
            'logging' => [
                'syslog_enabled' => false,
                'syslog_identity' => 'poweradmin',
                'syslog_facility' => LOG_USER,
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertTrue($validator->validate(), "Languages with spaces should be valid after trimming");
        $this->assertEmpty($validator->getErrors());
    }

    public function testMultipleValidationErrors(): void
    {
        $config = [
            'interface' => [
                'rows_per_page' => 'invalid',      // Should cause error
                'language' => '',                  // Should cause error
                'enabled_languages' => '',         // Should cause error
            ],
            'logging' => [
                'syslog_enabled' => 'not_boolean', // Should cause error
                'syslog_identity' => 'poweradmin',
                'syslog_facility' => LOG_USER,
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $errors = $validator->getErrors();

        // Should have multiple errors
        $this->assertCount(4, $errors);
        $this->assertArrayHasKey('interface.rows_per_page', $errors);
        $this->assertArrayHasKey('interface.language', $errors);
        $this->assertArrayHasKey('interface.enabled_languages', $errors);
        $this->assertArrayHasKey('logging.syslog_enabled', $errors);
    }

    public function testMissingConfigSections(): void
    {
        $config = []; // Empty config

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $errors = $validator->getErrors();

        // Should have errors for missing required values
        $this->assertNotEmpty($errors);
    }

    public function testPartialConfigSections(): void
    {
        $config = [
            'interface' => [
                'rows_per_page' => 10,
                // Missing language and enabled_languages
            ],
            'logging' => [
                'syslog_enabled' => false,
                // Missing identity and facility - but they're only required when enabled
            ],
        ];

        $validator = new ConfigValidator($config);

        $this->assertFalse($validator->validate());
        $errors = $validator->getErrors();

        // Should have errors for missing interface values
        $this->assertArrayHasKey('interface.language', $errors);
    }
}
