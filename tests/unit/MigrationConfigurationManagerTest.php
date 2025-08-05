<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\MigrationConfigurationManager;

class MigrationConfigurationManagerTest extends TestCase
{
    private MigrationConfigurationManager $migrationManager;

    protected function setUp(): void
    {
        $this->migrationManager = new MigrationConfigurationManager();
    }

    public function testBasicDatabaseMigration(): void
    {
        $legacyConfigFile = $this->createTempConfigFile([
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_user' => 'poweradmin',
            'db_pass' => 'secret123',
            'db_name' => 'poweradmin_db',
            'db_type' => 'mysql',
            'db_charset' => 'utf8mb4',
            'db_debug' => false,
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        $this->assertArrayHasKey('database', $result);
        $this->assertEquals('localhost', $result['database']['host']);
        $this->assertIsInt($result['database']['port']);
        $this->assertEquals(3306, $result['database']['port']);
        $this->assertEquals('poweradmin', $result['database']['user']);
        $this->assertEquals('secret123', $result['database']['password']);
        $this->assertEquals('poweradmin_db', $result['database']['name']);
        $this->assertEquals('mysql', $result['database']['type']);
        $this->assertEquals('utf8mb4', $result['database']['charset']);
        $this->assertIsBool($result['database']['debug']);
        $this->assertFalse($result['database']['debug']);

        unlink($legacyConfigFile);
    }

    public function testInterfaceSettingsMigrationWithTypeConversion(): void
    {
        $legacyConfigFile = $this->createTempConfigFile([
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN,de_DE,fr_FR',
            'iface_rowamount' => '25',  // String that should become int
            'iface_expire' => '3600',   // String that should become int
            'iface_zonelist_serial' => 'true',  // String that should become bool
            'iface_zone_comments' => '1',       // String that should become bool
            'iface_add_reverse_record' => 'false', // String that should become bool
            'iface_title' => 'My Poweradmin',
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        $this->assertArrayHasKey('interface', $result);

        // Test string values
        $this->assertIsString($result['interface']['language']);
        $this->assertEquals('en_EN', $result['interface']['language']);
        $this->assertIsString($result['interface']['enabled_languages']);
        $this->assertEquals('en_EN,de_DE,fr_FR', $result['interface']['enabled_languages']);
        $this->assertIsString($result['interface']['title']);
        $this->assertEquals('My Poweradmin', $result['interface']['title']);

        // Test integer conversions
        $this->assertIsInt($result['interface']['rows_per_page']);
        $this->assertEquals(25, $result['interface']['rows_per_page']);
        $this->assertIsInt($result['interface']['session_timeout']);
        $this->assertEquals(3600, $result['interface']['session_timeout']);

        // Test boolean conversions
        $this->assertIsBool($result['interface']['display_serial_in_zone_list']);
        $this->assertTrue($result['interface']['display_serial_in_zone_list']);
        $this->assertIsBool($result['interface']['show_zone_comments']);
        $this->assertTrue($result['interface']['show_zone_comments']);
        $this->assertIsBool($result['interface']['add_reverse_record']);
        $this->assertFalse($result['interface']['add_reverse_record']);

        unlink($legacyConfigFile);
    }

    public function testBooleanConversionVariousFormats(): void
    {
        $legacyConfigFile = $this->createTempConfigFile([
            'syslog_use' => 'true',
            'dblog_use' => 'TRUE',
            'iface_zone_comments' => '1',
            'iface_add_reverse_record' => 'yes',
            'iface_add_domain_record' => 'YES',
            'iface_zonelist_serial' => 'on',
            'iface_zonelist_template' => 'false',
            'iface_edit_show_id' => 'FALSE',
            'iface_record_comments' => '0',
            'iface_search_group_records' => 'no',
            'iface_migrations_show' => 'off',
            'db_debug' => false, // Already boolean
            'login_token_validation' => true, // Already boolean
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        // Test various true formats
        $this->assertTrue($result['logging']['syslog_enabled']);
        $this->assertTrue($result['logging']['database_enabled']);
        $this->assertTrue($result['interface']['show_zone_comments']);
        $this->assertTrue($result['interface']['add_reverse_record']);
        $this->assertTrue($result['interface']['add_domain_record']);
        $this->assertTrue($result['interface']['display_serial_in_zone_list']);

        // Test various false formats
        $this->assertFalse($result['interface']['display_template_in_zone_list']);
        $this->assertFalse($result['interface']['show_record_id']);
        $this->assertFalse($result['interface']['show_record_comments']);
        $this->assertFalse($result['interface']['search_group_records']);
        $this->assertFalse($result['interface']['show_migrations']);

        // Test already boolean values
        $this->assertFalse($result['database']['debug']);
        $this->assertTrue($result['security']['login_token_validation']);

        unlink($legacyConfigFile);
    }

    public function testLoggingSettingsMigration(): void
    {
        $legacyConfigFile = $this->createTempConfigFile([
            'syslog_use' => true,
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
            'dblog_use' => 'false',
            'logger_type' => 'file',
            'logger_level' => 'info',
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        $this->assertArrayHasKey('logging', $result);
        $this->assertIsBool($result['logging']['syslog_enabled']);
        $this->assertTrue($result['logging']['syslog_enabled']);
        $this->assertIsString($result['logging']['syslog_identity']);
        $this->assertEquals('poweradmin', $result['logging']['syslog_identity']);
        $this->assertIsInt($result['logging']['syslog_facility']);
        $this->assertEquals(LOG_USER, $result['logging']['syslog_facility']);
        $this->assertIsBool($result['logging']['database_enabled']);
        $this->assertFalse($result['logging']['database_enabled']);
        $this->assertIsString($result['logging']['type']);
        $this->assertEquals('file', $result['logging']['type']);
        $this->assertIsString($result['logging']['level']);
        $this->assertEquals('info', $result['logging']['level']);

        unlink($legacyConfigFile);
    }

    public function testSecuritySettingsMigration(): void
    {
        $legacyConfigFile = $this->createTempConfigFile([
            'session_key' => 'my-secret-key-123',
            'password_encryption' => 'bcrypt',
            'password_encryption_cost' => '12',  // String that should become int
            'login_token_validation' => 'true',  // String that should become bool
            'global_token_validation' => 'false', // String that should become bool
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        $this->assertArrayHasKey('security', $result);
        $this->assertIsString($result['security']['session_key']);
        $this->assertEquals('my-secret-key-123', $result['security']['session_key']);
        $this->assertIsString($result['security']['password_encryption']);
        $this->assertEquals('bcrypt', $result['security']['password_encryption']);
        $this->assertIsInt($result['security']['password_cost']);
        $this->assertEquals(12, $result['security']['password_cost']);
        $this->assertIsBool($result['security']['login_token_validation']);
        $this->assertTrue($result['security']['login_token_validation']);
        $this->assertIsBool($result['security']['global_token_validation']);
        $this->assertFalse($result['security']['global_token_validation']);

        unlink($legacyConfigFile);
    }

    public function testStyleMigrationLegacyThemes(): void
    {
        $legacyConfigFile = $this->createTempConfigFile([
            'iface_style' => 'ignite',
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        $this->assertEquals('light', $result['interface']['style']);
        $this->assertEquals('default', $result['interface']['theme']);

        unlink($legacyConfigFile);

        // Test spark -> dark mapping
        $legacyConfigFile = $this->createTempConfigFile([
            'iface_style' => 'spark',
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        $this->assertEquals('dark', $result['interface']['style']);
        $this->assertEquals('default', $result['interface']['theme']);

        unlink($legacyConfigFile);

        // Test unknown style -> light mapping
        $legacyConfigFile = $this->createTempConfigFile([
            'iface_style' => 'unknown-theme',
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        $this->assertEquals('light', $result['interface']['style']);
        $this->assertEquals('default', $result['interface']['theme']);

        unlink($legacyConfigFile);
    }

    public function testSOAStringParsing(): void
    {
        $legacyConfigFile = $this->createTempConfigFile([
            'dns_soa' => '28800 7200 604800 86400',
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        $this->assertArrayHasKey('dns', $result);
        $this->assertIsInt($result['dns']['soa_refresh']);
        $this->assertEquals(28800, $result['dns']['soa_refresh']);
        $this->assertIsInt($result['dns']['soa_retry']);
        $this->assertEquals(7200, $result['dns']['soa_retry']);
        $this->assertIsInt($result['dns']['soa_expire']);
        $this->assertEquals(604800, $result['dns']['soa_expire']);
        $this->assertIsInt($result['dns']['soa_minimum']);
        $this->assertEquals(86400, $result['dns']['soa_minimum']);

        unlink($legacyConfigFile);
    }

    public function testIncompleteSOAStringUsesDefaults(): void
    {
        $legacyConfigFile = $this->createTempConfigFile([
            'dns_soa' => '28800 7200', // Only 2 values instead of 4
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        $this->assertArrayHasKey('dns', $result);
        // Should use default values when SOA string is incomplete
        $this->assertEquals(28800, $result['dns']['soa_refresh']);
        $this->assertEquals(7200, $result['dns']['soa_retry']);
        $this->assertEquals(604800, $result['dns']['soa_expire']);
        $this->assertEquals(86400, $result['dns']['soa_minimum']);

        unlink($legacyConfigFile);
    }

    public function testOnlyMigratesExistingValues(): void
    {
        $legacyConfigFile = $this->createTempConfigFile([
            'db_host' => 'localhost',
            'iface_lang' => 'en_EN',
            // Intentionally missing many other values
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        // Should have the structure but only populated values
        $this->assertArrayHasKey('database', $result);
        $this->assertArrayHasKey('interface', $result);
        $this->assertArrayHasKey('security', $result);
        $this->assertArrayHasKey('logging', $result);

        // Database should only have host
        $this->assertArrayHasKey('host', $result['database']);
        $this->assertEquals('localhost', $result['database']['host']);
        $this->assertArrayNotHasKey('port', $result['database']);
        $this->assertArrayNotHasKey('user', $result['database']);

        // Interface should only have language
        $this->assertArrayHasKey('language', $result['interface']);
        $this->assertEquals('en_EN', $result['interface']['language']);
        $this->assertArrayNotHasKey('rows_per_page', $result['interface']);
        $this->assertArrayNotHasKey('title', $result['interface']);

        // Empty sections should remain empty
        $this->assertEmpty($result['security']);
        $this->assertEmpty($result['logging']);

        unlink($legacyConfigFile);
    }

    public function testNumericStringConversions(): void
    {
        $legacyConfigFile = $this->createTempConfigFile([
            'db_port' => '3306',
            'iface_rowamount' => '50',
            'iface_expire' => '7200',
            'password_encryption_cost' => '10',
            'syslog_facility' => '8', // LOG_USER as string
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        $this->assertIsInt($result['database']['port']);
        $this->assertEquals(3306, $result['database']['port']);
        $this->assertIsInt($result['interface']['rows_per_page']);
        $this->assertEquals(50, $result['interface']['rows_per_page']);
        $this->assertIsInt($result['interface']['session_timeout']);
        $this->assertEquals(7200, $result['interface']['session_timeout']);
        $this->assertIsInt($result['security']['password_cost']);
        $this->assertEquals(10, $result['security']['password_cost']);
        $this->assertIsInt($result['logging']['syslog_facility']);
        $this->assertEquals(8, $result['logging']['syslog_facility']);

        unlink($legacyConfigFile);
    }

    public function testComplexMigrationScenario(): void
    {
        // Test a realistic legacy config with mixed types
        $legacyConfigFile = $this->createTempConfigFile([
            'db_host' => 'db.example.com',
            'db_port' => '3306',
            'db_user' => 'poweradmin',
            'db_pass' => 'secure-password',
            'db_name' => 'poweradmin_db',
            'db_type' => 'mysql',
            'db_debug' => 'false',
            'iface_lang' => 'en_EN',
            'iface_enabled_languages' => 'en_EN,de_DE,fr_FR',
            'iface_rowamount' => '30',
            'iface_title' => 'Company DNS Manager',
            'iface_expire' => '1800',
            'iface_zonelist_serial' => 'true',
            'iface_zone_comments' => '1',
            'session_key' => 'very-secret-key',
            'password_encryption' => 'bcrypt',
            'password_encryption_cost' => '12',
            'syslog_use' => 'true',
            'syslog_ident' => 'poweradmin',
            'syslog_facility' => LOG_USER,
            'dns_hostmaster' => 'hostmaster@example.com',
            'dns_ns1' => 'ns1.example.com',
            'dns_ns2' => 'ns2.example.com',
            'dns_ttl' => '86400',
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

        // Verify complete structure
        $this->assertArrayHasKey('database', $result);
        $this->assertArrayHasKey('interface', $result);
        $this->assertArrayHasKey('security', $result);
        $this->assertArrayHasKey('logging', $result);
        $this->assertArrayHasKey('dns', $result);

        // Verify database section
        $this->assertEquals('db.example.com', $result['database']['host']);
        $this->assertIsInt($result['database']['port']);
        $this->assertEquals(3306, $result['database']['port']);
        $this->assertIsBool($result['database']['debug']);
        $this->assertFalse($result['database']['debug']);

        // Verify interface section
        $this->assertEquals('en_EN', $result['interface']['language']);
        $this->assertIsInt($result['interface']['rows_per_page']);
        $this->assertEquals(30, $result['interface']['rows_per_page']);
        $this->assertEquals('Company DNS Manager', $result['interface']['title']);
        $this->assertIsBool($result['interface']['display_serial_in_zone_list']);
        $this->assertTrue($result['interface']['display_serial_in_zone_list']);

        // Verify security section
        $this->assertEquals('very-secret-key', $result['security']['session_key']);
        $this->assertIsInt($result['security']['password_cost']);
        $this->assertEquals(12, $result['security']['password_cost']);

        // Verify logging section
        $this->assertIsBool($result['logging']['syslog_enabled']);
        $this->assertTrue($result['logging']['syslog_enabled']);
        $this->assertEquals('poweradmin', $result['logging']['syslog_identity']);

        // Verify DNS section
        $this->assertEquals('hostmaster@example.com', $result['dns']['hostmaster']);
        $this->assertEquals('ns1.example.com', $result['dns']['ns1']);
        $this->assertEquals('ns2.example.com', $result['dns']['ns2']);
        $this->assertIsInt($result['dns']['ttl']);
        $this->assertEquals(86400, $result['dns']['ttl']);

        unlink($legacyConfigFile);
    }

    private function createTempConfigFile(array $variables): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'config_test_');
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
}
