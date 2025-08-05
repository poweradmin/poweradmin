<?php

namespace integration;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\MigrationConfigurationManager;
use Poweradmin\Infrastructure\Configuration\ConfigValidator;

class ConfigMigrationWorkflowTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/poweradmin_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDir($this->tempDir);
    }

    public function testCompleteWorkflowFromLegacyToValidatedConfig(): void
    {
        // Step 1: Create a realistic legacy config file
        $legacyConfig = $this->createRealisticLegacyConfig();

        // Step 2: Run migration
        $migrationManager = new MigrationConfigurationManager();
        $migratedConfig = $migrationManager->migrateWithCustomMapping($legacyConfig);

        // Step 3: Validate migrated config passes validation
        $validator = new ConfigValidator($migratedConfig);
        $isValid = $validator->validate();

        if (!$isValid) {
            $this->fail("Migrated config failed validation with errors: " . print_r($validator->getErrors(), true));
        }

        $this->assertTrue($isValid, "Migrated config should pass validation");
        $this->assertEmpty($validator->getErrors(), "Should have no validation errors");

        // Step 4: Verify basic structure and types of migrated config
        $this->assertIsArray($migratedConfig);
        $this->assertArrayHasKey('database', $migratedConfig);
        $this->assertArrayHasKey('interface', $migratedConfig);

        // Verify the configuration structure is compatible with modern format
        $this->assertIsArray($migratedConfig['database']);
        $this->assertIsArray($migratedConfig['interface']);

        unlink($legacyConfig);
    }

    public function testWorkflowWithBugReportScenario(): void
    {
        // Recreate the exact scenario from the bug report
        $legacyConfig = $this->createTempConfigFile([
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN',
            'iface_rowamount' => 30,
            'iface_index' => 'cards',
            'syslog_use' => false,
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
        ]);

        // Migrate
        $migrationManager = new MigrationConfigurationManager();
        $migratedConfig = $migrationManager->migrateWithCustomMapping($legacyConfig);

        // This should NOT produce the validation errors mentioned in the bug report
        $validator = new ConfigValidator($migratedConfig);
        $isValid = $validator->validate();
        $errors = $validator->getErrors();

        // Assert no validation errors that were mentioned in the bug report
        $this->assertArrayNotHasKey('iface_index', $errors, "Should not have iface_index error (setting removed)");
        $this->assertArrayNotHasKey('iface_rowamount', $errors, "Should not have iface_rowamount error");
        $this->assertArrayNotHasKey('iface_lang', $errors, "Should not have iface_lang error");
        $this->assertArrayNotHasKey('iface_enabled_languages', $errors, "Should not have iface_enabled_languages error");
        $this->assertArrayNotHasKey('syslog_use', $errors, "Should not have syslog_use error");

        $this->assertTrue($isValid, "Bug report scenario should pass validation after fix");

        // Verify the migrated values are correct and properly typed
        $this->assertEquals('en_EN', $migratedConfig['interface']['language']);
        $this->assertEquals('en_EN', $migratedConfig['interface']['enabled_languages']);
        $this->assertIsInt($migratedConfig['interface']['rows_per_page']);
        $this->assertEquals(30, $migratedConfig['interface']['rows_per_page']);
        $this->assertIsBool($migratedConfig['logging']['syslog_enabled']);
        $this->assertFalse($migratedConfig['logging']['syslog_enabled']);

        unlink($legacyConfig);
    }

    public function testWorkflowWithTypicalUpgradeScenario(): void
    {
        // Simulate a typical 3.9.2 -> 4.0.0 upgrade scenario
        $legacyConfig = $this->createTempConfigFile([
            // Database settings (typical MySQL setup)
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_user' => 'poweradmin',
            'db_pass' => 'secure123!',
            'db_name' => 'poweradmin_db',
            'db_type' => 'mysql',
            'db_charset' => 'utf8mb4',
            'db_debug' => 'false',  // String instead of boolean

            // Interface settings
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN,de_DE,fr_FR',
            'iface_rowamount' => '25',  // String instead of int
            'iface_title' => 'Company DNS Server',
            'iface_expire' => '1800',   // String instead of int
            'iface_style' => 'ignite',  // Old theme name
            'iface_zonelist_serial' => 'true',    // String instead of bool
            'iface_zone_comments' => '1',         // String instead of bool
            'iface_add_reverse_record' => 'yes',  // String instead of bool

            // Security settings
            'session_key' => 'very-long-secret-key-12345',
            'password_encryption' => 'bcrypt',
            'password_encryption_cost' => '12',   // String instead of int

            // Logging settings
            'syslog_use' => 'true',        // String instead of bool
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
            'dblog_use' => 'false',        // String instead of bool

            // DNS settings
            'dns_hostmaster' => 'admin@company.com',
            'dns_ns1' => 'ns1.company.com',
            'dns_ns2' => 'ns2.company.com',
            'dns_ttl' => '86400',          // String instead of int
            'dns_soa' => '28800 7200 604800 86400',
        ]);

        // Step 1: Migrate
        $migrationManager = new MigrationConfigurationManager();
        $migratedConfig = $migrationManager->migrateWithCustomMapping($legacyConfig);

        // Step 2: Validate
        $validator = new ConfigValidator($migratedConfig);
        $isValid = $validator->validate();

        if (!$isValid) {
            $this->fail("Typical upgrade scenario failed validation: " . print_r($validator->getErrors(), true));
        }

        // Step 3: Verify all type conversions worked correctly

        // Database section
        $this->assertEquals('localhost', $migratedConfig['database']['host']);
        $this->assertIsInt($migratedConfig['database']['port']);
        $this->assertEquals(3306, $migratedConfig['database']['port']);
        $this->assertIsBool($migratedConfig['database']['debug']);
        $this->assertFalse($migratedConfig['database']['debug']);

        // Interface section
        $this->assertEquals('en_EN', $migratedConfig['interface']['language']);
        $this->assertIsInt($migratedConfig['interface']['rows_per_page']);
        $this->assertEquals(25, $migratedConfig['interface']['rows_per_page']);
        $this->assertEquals('Company DNS Server', $migratedConfig['interface']['title']);
        $this->assertIsInt($migratedConfig['interface']['session_timeout']);
        $this->assertEquals(1800, $migratedConfig['interface']['session_timeout']);
        $this->assertEquals('light', $migratedConfig['interface']['style']); // ignite -> light
        $this->assertEquals('default', $migratedConfig['interface']['theme']);
        $this->assertIsBool($migratedConfig['interface']['display_serial_in_zone_list']);
        $this->assertTrue($migratedConfig['interface']['display_serial_in_zone_list']);
        $this->assertIsBool($migratedConfig['interface']['show_zone_comments']);
        $this->assertTrue($migratedConfig['interface']['show_zone_comments']);
        $this->assertIsBool($migratedConfig['interface']['add_reverse_record']);
        $this->assertTrue($migratedConfig['interface']['add_reverse_record']);

        // Security section
        $this->assertEquals('very-long-secret-key-12345', $migratedConfig['security']['session_key']);
        $this->assertIsInt($migratedConfig['security']['password_cost']);
        $this->assertEquals(12, $migratedConfig['security']['password_cost']);

        // Logging section
        $this->assertIsBool($migratedConfig['logging']['syslog_enabled']);
        $this->assertTrue($migratedConfig['logging']['syslog_enabled']);
        $this->assertEquals('poweradmin', $migratedConfig['logging']['syslog_identity']);
        $this->assertIsBool($migratedConfig['logging']['database_enabled']);
        $this->assertFalse($migratedConfig['logging']['database_enabled']);

        // DNS section
        $this->assertEquals('admin@company.com', $migratedConfig['dns']['hostmaster']);
        $this->assertIsInt($migratedConfig['dns']['ttl']);
        $this->assertEquals(86400, $migratedConfig['dns']['ttl']);
        $this->assertIsInt($migratedConfig['dns']['soa_refresh']);
        $this->assertEquals(28800, $migratedConfig['dns']['soa_refresh']);

        unlink($legacyConfig);
    }

    public function testWorkflowPreservesOnlyCustomizedSettings(): void
    {
        // Create a minimal legacy config (user only customized a few settings)
        $legacyConfig = $this->createTempConfigFile([
            'db_host' => 'custom.db.server.com',
            'iface_lang' => 'de_DE',
            'dns_hostmaster' => 'dns-admin@example.org',
        ]);

        $migrationManager = new MigrationConfigurationManager();
        $migratedConfig = $migrationManager->migrateWithCustomMapping($legacyConfig);

        // Should only have the customized settings, not defaults
        $this->assertArrayHasKey('host', $migratedConfig['database']);
        $this->assertEquals('custom.db.server.com', $migratedConfig['database']['host']);
        $this->assertArrayNotHasKey('port', $migratedConfig['database']); // Not customized, so not migrated
        $this->assertArrayNotHasKey('user', $migratedConfig['database']);

        $this->assertArrayHasKey('language', $migratedConfig['interface']);
        $this->assertEquals('de_DE', $migratedConfig['interface']['language']);
        $this->assertArrayNotHasKey('rows_per_page', $migratedConfig['interface']); // Not customized

        $this->assertArrayHasKey('hostmaster', $migratedConfig['dns']);
        $this->assertEquals('dns-admin@example.org', $migratedConfig['dns']['hostmaster']);
        $this->assertArrayNotHasKey('ns1', $migratedConfig['dns']); // Not customized

        // Empty sections should remain empty
        $this->assertEmpty($migratedConfig['security']);
        $this->assertEmpty($migratedConfig['logging']);

        unlink($legacyConfig);
    }

    public function testWorkflowWithConfigFileGeneration(): void
    {
        // Test generating config file in proper format
        $legacyConfig = $this->createTempConfigFile([
            'db_host' => 'localhost',
            'db_name' => 'poweradmin',
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN,de_DE',
            'iface_rowamount' => 20,  // Add required field
            'syslog_use' => false,
        ]);

        // Migrate
        $migrationManager = new MigrationConfigurationManager();
        $migratedConfig = $migrationManager->migrateWithCustomMapping($legacyConfig);

        // Write to new config file
        $newConfigFile = $this->tempDir . '/settings.php';
        $this->writeConfigFile($newConfigFile, $migratedConfig);

        // Test that the file was written correctly and can be loaded
        $this->assertFileExists($newConfigFile);
        $loadedConfig = require $newConfigFile;

        $this->assertIsArray($loadedConfig);
        $this->assertEquals('localhost', $loadedConfig['database']['host']);
        $this->assertEquals('poweradmin', $loadedConfig['database']['name']);
        $this->assertEquals('en_EN', $loadedConfig['interface']['language']);

        // Test that it validates properly
        $validator = new ConfigValidator($loadedConfig);
        $this->assertTrue($validator->validate());

        unlink($legacyConfig);
    }

    public function testWorkflowPerformanceWithLargeConfig(): void
    {
        // Create a large legacy config to test performance
        $largeConfig = [];
        for ($i = 0; $i < 100; $i++) {
            $largeConfig["custom_setting_$i"] = "value_$i";
        }

        // Add required settings
        $largeConfig['iface_lang'] = 'en_EN';
        $largeConfig['iface_enabled_languages'] = 'en_EN,de_DE,fr_FR,es_ES,it_IT';
        $largeConfig['iface_rowamount'] = '50';
        $largeConfig['syslog_use'] = 'false';

        $legacyConfigFile = $this->createTempConfigFile($largeConfig);

        $startTime = microtime(true);

        $migrationManager = new MigrationConfigurationManager();
        $migratedConfig = $migrationManager->migrateWithCustomMapping($legacyConfigFile);

        $validator = new ConfigValidator($migratedConfig);
        $isValid = $validator->validate();

        $endTime = microtime(true);

        // Should complete quickly even with large config
        $this->assertLessThan(0.5, $endTime - $startTime, "Large config migration should be fast");
        $this->assertTrue($isValid, "Large config should validate successfully");

        unlink($legacyConfigFile);
    }

    private function createRealisticLegacyConfig(): string
    {
        return $this->createTempConfigFile([
            // Database
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_user' => 'poweradmin',
            'db_pass' => 'password123',
            'db_name' => 'poweradmin',
            'db_type' => 'mysql',

            // Interface
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN,de_DE',
            'iface_rowamount' => '20',
            'iface_title' => 'PowerAdmin DNS',
            'iface_expire' => '3600',

            // Security
            'session_key' => 'random-session-key-abc123',
            'password_encryption' => 'bcrypt',

            // Logging
            'syslog_use' => 'false',
            'syslog_ident' => 'poweradmin',

            // DNS
            'dns_hostmaster' => 'hostmaster@example.com',
            'dns_ns1' => 'ns1.example.com',
            'dns_ns2' => 'ns2.example.com',
        ]);
    }

    private function createTempConfigFile(array $variables): string
    {
        $tempFile = tempnam($this->tempDir, 'config_');
        $content = "<?php\n";

        foreach ($variables as $name => $value) {
            if (is_string($value)) {
                $content .= "\$$name = '$value';\n";
            } elseif (is_bool($value)) {
                $content .= "\$$name = " . ($value ? 'true' : 'false') . ";\n";
            } elseif (is_int($value)) {
                $content .= "\$$name = $value;\n";
            } else {
                $content .= "\$$name = " . var_export($value, true) . ";\n";
            }
        }

        file_put_contents($tempFile, $content);
        return $tempFile;
    }

    private function writeConfigFile(string $filePath, array $config): void
    {
        $content = "<?php\n";
        $content .= "/**\n";
        $content .= " * Poweradmin Configuration\n";
        $content .= " * Generated by integration test\n";
        $content .= " */\n\n";

        $formattedConfig = var_export($config, true);
        $formattedConfig = str_replace('array (', '[', $formattedConfig);
        $formattedConfig = str_replace(')', ']', $formattedConfig);

        $content .= "return $formattedConfig;\n";

        file_put_contents($filePath, $content);
    }

    private function cleanupTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $fullPath = $dir . '/' . $file;
            if (is_dir($fullPath)) {
                $this->cleanupTempDir($fullPath);
            } else {
                unlink($fullPath);
            }
        }
        rmdir($dir);
    }
}
