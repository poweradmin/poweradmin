<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\MigrationConfigurationManager;

class MigrationErrorHandlingTest extends TestCase
{
    private MigrationConfigurationManager $migrationManager;

    protected function setUp(): void
    {
        $this->migrationManager = new MigrationConfigurationManager();
    }

    public function testMigrationWithNonExistentFile(): void
    {
        $nonExistentFile = '/tmp/non_existent_config_file_' . uniqid() . '.php';

        // Should trigger a PHP warning but still return a result
        set_error_handler(function () {
            return true;
        }); // Suppress warning
        $result = $this->migrationManager->migrateWithCustomMapping($nonExistentFile);
        restore_error_handler();

        // Should still return empty structure even with warning
        $this->assertIsArray($result);
        $this->assertArrayHasKey('database', $result);
        $this->assertEmpty($result['database']);
    }

    public function testMigrationWithEmptyFile(): void
    {
        $emptyFile = $this->createTempConfigFile([]);

        $result = $this->migrationManager->migrateWithCustomMapping($emptyFile);

        // Should return empty structure with all sections but no values
        $this->assertArrayHasKey('database', $result);
        $this->assertArrayHasKey('security', $result);
        $this->assertArrayHasKey('interface', $result);
        $this->assertArrayHasKey('dns', $result);
        $this->assertArrayHasKey('dnssec', $result);
        $this->assertArrayHasKey('pdns_api', $result);
        $this->assertArrayHasKey('logging', $result);
        $this->assertArrayHasKey('ldap', $result);
        $this->assertArrayHasKey('misc', $result);

        // All sections should be empty
        $this->assertEmpty($result['database']);
        $this->assertEmpty($result['security']);
        $this->assertEmpty($result['interface']);
        $this->assertEmpty($result['dns']);
        $this->assertEmpty($result['dnssec']);
        $this->assertEmpty($result['pdns_api']);
        $this->assertEmpty($result['logging']);
        $this->assertEmpty($result['ldap']);
        $this->assertEmpty($result['misc']);

        unlink($emptyFile);
    }

    public function testMigrationWithInvalidPhpSyntax(): void
    {
        $invalidFile = tempnam(sys_get_temp_dir(), 'invalid_config_');
        file_put_contents($invalidFile, "<?php\n\$invalid syntax here");

        $this->expectException(\ParseError::class);

        $this->migrationManager->migrateWithCustomMapping($invalidFile);

        unlink($invalidFile);
    }

    public function testMigrationWithOnlyComments(): void
    {
        $commentOnlyFile = tempnam(sys_get_temp_dir(), 'comment_config_');
        file_put_contents($commentOnlyFile, "<?php\n// This is just a comment\n/* Another comment */\n");

        $result = $this->migrationManager->migrateWithCustomMapping($commentOnlyFile);

        // Should return basic structure with empty sections
        $this->assertIsArray($result);
        $this->assertArrayHasKey('database', $result);
        $this->assertEmpty($result['database']);

        unlink($commentOnlyFile);
    }

    public function testMigrationWithUnexpectedVariableTypes(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'unusual_config_');
        $content = "<?php\n";
        $content .= "\$db_host = ['array', 'as', 'host'];\n";  // Array instead of string
        $content .= "\$iface_rowamount = 'not-a-number';\n";   // String that can't be converted to int
        $content .= "\$syslog_use = 'maybe';\n";               // String that's not a clear boolean

        file_put_contents($tempFile, $content);

        $result = $this->migrationManager->migrateWithCustomMapping($tempFile);

        // Should still process but with type conversion
        $this->assertIsString($result['database']['host']); // Array converted to string "Array"
        $this->assertEquals('Array', $result['database']['host']);
        $this->assertIsInt($result['interface']['rows_per_page']); // Should be 0 (PHP int cast of non-numeric string)
        $this->assertEquals(0, $result['interface']['rows_per_page']);
        $this->assertIsBool($result['logging']['syslog_enabled']); // Should convert to true (non-empty string)
        $this->assertTrue($result['logging']['syslog_enabled']);

        unlink($tempFile);
    }

    public function testMigrationWithMalformedSOAString(): void
    {
        $tempFile = $this->createTempConfigFile([
            'dns_soa' => 'invalid soa format with too many spaces and words',
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($tempFile);

        // Should fall back to defaults when SOA string is malformed
        $this->assertArrayHasKey('dns', $result);
        $this->assertEquals(28800, $result['dns']['soa_refresh']);   // Default
        $this->assertEquals(7200, $result['dns']['soa_retry']);      // Default
        $this->assertEquals(604800, $result['dns']['soa_expire']);   // Default
        $this->assertEquals(86400, $result['dns']['soa_minimum']);   // Default

        unlink($tempFile);
    }

    public function testMigrationWithEmptySOAString(): void
    {
        $tempFile = $this->createTempConfigFile([
            'dns_soa' => '',
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($tempFile);

        // Should fall back to defaults when SOA string is empty
        $this->assertArrayHasKey('dns', $result);
        $this->assertEquals(28800, $result['dns']['soa_refresh']);
        $this->assertEquals(7200, $result['dns']['soa_retry']);
        $this->assertEquals(604800, $result['dns']['soa_expire']);
        $this->assertEquals(86400, $result['dns']['soa_minimum']);

        unlink($tempFile);
    }

    public function testMigrationWithNullValues(): void
    {
        $tempFile = $this->createTempConfigFile([
            'db_host' => null,
            'iface_lang' => null,
            'syslog_use' => null,
            'iface_rowamount' => null,
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($tempFile);

        // Null values should be type-converted appropriately
        if (isset($result['database']['host'])) {
            $this->assertIsString($result['database']['host']);
            $this->assertEquals('', $result['database']['host']); // null becomes empty string
        }

        if (isset($result['interface']['language'])) {
            $this->assertIsString($result['interface']['language']);
            $this->assertEquals('', $result['interface']['language']); // null becomes empty string
        }

        if (isset($result['logging']['syslog_enabled'])) {
            $this->assertIsBool($result['logging']['syslog_enabled']);
            $this->assertFalse($result['logging']['syslog_enabled']); // null becomes false
        }

        if (isset($result['interface']['rows_per_page'])) {
            $this->assertIsInt($result['interface']['rows_per_page']);
            $this->assertEquals(0, $result['interface']['rows_per_page']); // null becomes 0
        }

        unlink($tempFile);
    }

    public function testMigrationWithExtremelyLongValues(): void
    {
        $longString = str_repeat('a', 10000); // 10KB string

        $tempFile = $this->createTempConfigFile([
            'db_host' => $longString,
            'session_key' => $longString,
            'iface_title' => $longString,
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($tempFile);

        // Should handle long values without issues
        $this->assertEquals($longString, $result['database']['host']);
        $this->assertEquals($longString, $result['security']['session_key']);
        $this->assertEquals($longString, $result['interface']['title']);

        unlink($tempFile);
    }

    public function testMigrationWithSpecialCharacters(): void
    {
        $specialChars = "!@#$%^&*()_+-=[]{}|;':\",./<>?\n\t\r";

        $tempFile = $this->createTempConfigFile([
            'db_pass' => $specialChars,
            'session_key' => $specialChars,
            'syslog_ident' => $specialChars,
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($tempFile);

        // Should preserve special characters
        $this->assertEquals($specialChars, $result['database']['password']);
        $this->assertEquals($specialChars, $result['security']['session_key']);
        $this->assertEquals($specialChars, $result['logging']['syslog_identity']);

        unlink($tempFile);
    }

    public function testMigrationWithUnicodeCharacters(): void
    {
        $unicodeString = "héllo wörld 测试 🌟 ñoño";

        $tempFile = $this->createTempConfigFile([
            'iface_title' => $unicodeString,
            'dns_hostmaster' => $unicodeString,
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($tempFile);

        // Should preserve Unicode characters
        $this->assertEquals($unicodeString, $result['interface']['title']);
        $this->assertEquals($unicodeString, $result['dns']['hostmaster']);

        unlink($tempFile);
    }

    public function testMigrationWithNumericStringEdgeCases(): void
    {
        $tempFile = $this->createTempConfigFile([
            'iface_rowamount' => '000123',      // Leading zeros
            'iface_expire' => '42.7',           // Float as string
            'db_port' => '+3306',               // Positive sign
            'password_encryption_cost' => '08', // Octal-looking number
        ]);

        $result = $this->migrationManager->migrateWithCustomMapping($tempFile);

        // Should handle numeric edge cases properly
        $this->assertIsInt($result['interface']['rows_per_page']);
        $this->assertEquals(123, $result['interface']['rows_per_page']); // Leading zeros removed

        $this->assertIsInt($result['interface']['session_timeout']);
        $this->assertEquals(42, $result['interface']['session_timeout']); // Float truncated to int

        $this->assertIsInt($result['database']['port']);
        $this->assertEquals(3306, $result['database']['port']); // Positive sign handled

        $this->assertIsInt($result['security']['password_cost']);
        $this->assertEquals(8, $result['security']['password_cost']); // Treated as decimal, not octal

        unlink($tempFile);
    }

    public function testMigrationMemoryAndPerformance(): void
    {
        // Create a config with many variables to test memory usage
        $manyVariables = [];
        for ($i = 0; $i < 1000; $i++) {
            $manyVariables["custom_var_$i"] = "value_$i";
        }

        // Add some known variables
        $manyVariables['db_host'] = 'localhost';
        $manyVariables['iface_lang'] = 'en_EN';

        $tempFile = $this->createTempConfigFile($manyVariables);

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $result = $this->migrationManager->migrateWithCustomMapping($tempFile);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        // Should complete in reasonable time and memory
        $this->assertLessThan(1.0, $endTime - $startTime, "Migration took too long");
        $this->assertLessThan(50 * 1024 * 1024, $endMemory - $startMemory, "Migration used too much memory"); // 50MB limit

        // Should still extract known variables correctly
        $this->assertEquals('localhost', $result['database']['host']);
        $this->assertEquals('en_EN', $result['interface']['language']);

        unlink($tempFile);
    }

    private function createTempConfigFile(array $variables): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'config_test_');
        $content = "<?php\n";

        foreach ($variables as $name => $value) {
            if (is_string($value)) {
                $escapedValue = addslashes($value);
                $content .= "\$$name = '$escapedValue';\n";
            } elseif (is_bool($value)) {
                $content .= "\$$name = " . ($value ? 'true' : 'false') . ";\n";
            } elseif (is_int($value)) {
                $content .= "\$$name = $value;\n";
            } elseif (is_null($value)) {
                $content .= "\$$name = null;\n";
            } else {
                $content .= "\$$name = " . var_export($value, true) . ";\n";
            }
        }

        file_put_contents($tempFile, $content);
        return $tempFile;
    }
}
