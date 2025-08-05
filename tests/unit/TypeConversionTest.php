<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\MigrationConfigurationManager;
use ReflectionClass;

class TypeConversionTest extends TestCase
{
    private MigrationConfigurationManager $migrationManager;

    protected function setUp(): void
    {
        $this->migrationManager = new MigrationConfigurationManager();
    }

    public function testConvertTypeMethodExists(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertType');
        $method->setAccessible(true);

        $this->assertTrue($method->isPrivate());
    }

    public function testConvertToBoolMethodExists(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertToBool');
        $method->setAccessible(true);

        $this->assertTrue($method->isPrivate());
    }

    public function testBooleanConversionStringTrue(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertToBool');
        $method->setAccessible(true);

        $trueValues = ['true', 'TRUE', 'True', 'TrUe'];

        foreach ($trueValues as $value) {
            $result = $method->invoke($this->migrationManager, $value);
            $this->assertTrue($result, "Failed to convert '$value' to true");
            $this->assertIsBool($result, "Result for '$value' is not boolean");
        }
    }

    public function testBooleanConversionStringFalse(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertToBool');
        $method->setAccessible(true);

        $falseValues = ['false', 'FALSE', 'False', 'FaLsE', '0', 'no', 'NO', 'off', 'OFF'];

        foreach ($falseValues as $value) {
            $result = $method->invoke($this->migrationManager, $value);
            $this->assertFalse($result, "Failed to convert '$value' to false");
            $this->assertIsBool($result, "Result for '$value' is not boolean");
        }
    }

    public function testBooleanConversionNumericStrings(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertToBool');
        $method->setAccessible(true);

        // Test '1' and 'yes' and 'on' variations
        $trueValues = ['1', 'yes', 'YES', 'Yes', 'on', 'ON', 'On'];

        foreach ($trueValues as $value) {
            $result = $method->invoke($this->migrationManager, $value);
            $this->assertTrue($result, "Failed to convert '$value' to true");
            $this->assertIsBool($result, "Result for '$value' is not boolean");
        }
    }

    public function testBooleanConversionNumericValues(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertToBool');
        $method->setAccessible(true);

        // Test numeric values
        $this->assertTrue($method->invoke($this->migrationManager, 1));
        $this->assertTrue($method->invoke($this->migrationManager, 123));
        $this->assertTrue($method->invoke($this->migrationManager, -1));
        $this->assertFalse($method->invoke($this->migrationManager, 0));
        $this->assertFalse($method->invoke($this->migrationManager, 0.0));
    }

    public function testBooleanConversionExistingBooleans(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertToBool');
        $method->setAccessible(true);

        // Test already boolean values
        $this->assertTrue($method->invoke($this->migrationManager, true));
        $this->assertFalse($method->invoke($this->migrationManager, false));
    }

    public function testBooleanConversionEdgeCases(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertToBool');
        $method->setAccessible(true);

        // Test edge cases
        $this->assertFalse($method->invoke($this->migrationManager, null));
        $this->assertFalse($method->invoke($this->migrationManager, ''));
        $this->assertFalse($method->invoke($this->migrationManager, '   '));
        $this->assertTrue($method->invoke($this->migrationManager, 'any-other-string'));
        $this->assertFalse($method->invoke($this->migrationManager, [])); // Empty array is falsy in PHP boolean context
        $this->assertTrue($method->invoke($this->migrationManager, [1, 2, 3])); // Non-empty array is truthy
    }

    public function testBooleanConversionWhitespace(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertToBool');
        $method->setAccessible(true);

        // Test values with whitespace (should be trimmed)
        $this->assertTrue($method->invoke($this->migrationManager, '  true  '));
        $this->assertTrue($method->invoke($this->migrationManager, "\ttrue\n"));
        $this->assertTrue($method->invoke($this->migrationManager, ' 1 '));
        $this->assertTrue($method->invoke($this->migrationManager, ' yes '));
        $this->assertTrue($method->invoke($this->migrationManager, ' on '));

        $this->assertFalse($method->invoke($this->migrationManager, '  false  '));
        $this->assertFalse($method->invoke($this->migrationManager, "\t0\n"));
        $this->assertFalse($method->invoke($this->migrationManager, ' no '));
        $this->assertFalse($method->invoke($this->migrationManager, ' off '));
    }

    public function testConvertTypeInteger(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertType');
        $method->setAccessible(true);

        // Test integer conversions
        $this->assertSame(123, $method->invoke($this->migrationManager, '123', 'int'));
        $this->assertSame(0, $method->invoke($this->migrationManager, '0', 'int'));
        $this->assertSame(-456, $method->invoke($this->migrationManager, '-456', 'int'));
        $this->assertSame(42, $method->invoke($this->migrationManager, 42, 'int'));
        $this->assertSame(1, $method->invoke($this->migrationManager, true, 'int'));
        $this->assertSame(0, $method->invoke($this->migrationManager, false, 'int'));
    }

    public function testConvertTypeString(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertType');
        $method->setAccessible(true);

        // Test string conversions
        $this->assertSame('hello', $method->invoke($this->migrationManager, 'hello', 'string'));
        $this->assertSame('123', $method->invoke($this->migrationManager, 123, 'string'));
        $this->assertSame('1', $method->invoke($this->migrationManager, true, 'string'));
        $this->assertSame('', $method->invoke($this->migrationManager, false, 'string'));
        $this->assertSame('0', $method->invoke($this->migrationManager, 0, 'string'));
    }

    public function testConvertTypeBoolean(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertType');
        $method->setAccessible(true);

        // Test boolean conversions (should call convertToBool)
        $this->assertTrue($method->invoke($this->migrationManager, 'true', 'bool'));
        $this->assertFalse($method->invoke($this->migrationManager, 'false', 'bool'));
        $this->assertTrue($method->invoke($this->migrationManager, '1', 'bool'));
        $this->assertFalse($method->invoke($this->migrationManager, '0', 'bool'));
        $this->assertTrue($method->invoke($this->migrationManager, 'yes', 'bool'));
        $this->assertFalse($method->invoke($this->migrationManager, 'no', 'bool'));
    }

    public function testConvertTypeUnknownType(): void
    {
        $reflection = new ReflectionClass($this->migrationManager);
        $method = $reflection->getMethod('convertType');
        $method->setAccessible(true);

        // Test unknown type (should return original value)
        $originalValue = 'test-value';
        $result = $method->invoke($this->migrationManager, $originalValue, 'unknown');
        $this->assertSame($originalValue, $result);
    }

    public function testRealWorldBooleanMigrationScenarios(): void
    {
        // Test scenarios that would commonly appear in legacy configs
        $scenarios = [
            // Common PHP config patterns
            ['value' => true, 'expected' => true],
            ['value' => false, 'expected' => false],
            ['value' => 'true', 'expected' => true],
            ['value' => 'false', 'expected' => false],
            ['value' => '1', 'expected' => true],
            ['value' => '0', 'expected' => false],

            // Apache/web server style configs
            ['value' => 'yes', 'expected' => true],
            ['value' => 'no', 'expected' => false],
            ['value' => 'on', 'expected' => true],
            ['value' => 'off', 'expected' => false],

            // Case variations that might appear
            ['value' => 'TRUE', 'expected' => true],
            ['value' => 'FALSE', 'expected' => false],
            ['value' => 'YES', 'expected' => true],
            ['value' => 'NO', 'expected' => false],
            ['value' => 'ON', 'expected' => true],
            ['value' => 'OFF', 'expected' => false],

            // Edge cases
            ['value' => '', 'expected' => false],
            ['value' => null, 'expected' => false],
            ['value' => 'random-string', 'expected' => true],
        ];

        foreach ($scenarios as $index => $scenario) {
            $legacyConfigFile = $this->createTempConfigFile([
                'syslog_use' => $scenario['value'],
            ]);

            $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

            $this->assertArrayHasKey('logging', $result);
            $this->assertArrayHasKey('syslog_enabled', $result['logging']);
            $this->assertIsBool($result['logging']['syslog_enabled'], "Scenario $index: Result is not boolean");
            $this->assertSame(
                $scenario['expected'],
                $result['logging']['syslog_enabled'],
                "Scenario $index: Expected " . var_export($scenario['expected'], true) . " for value " . var_export($scenario['value'], true)
            );

            unlink($legacyConfigFile);
        }
    }

    public function testRealWorldIntegerMigrationScenarios(): void
    {
        $scenarios = [
            // Strings that should become integers
            ['value' => '30', 'expected' => 30],
            ['value' => '0', 'expected' => 0],
            ['value' => '-5', 'expected' => -5],

            // Already integers
            ['value' => 25, 'expected' => 25],
            ['value' => 0, 'expected' => 0],

            // Edge cases (PHP int casting behavior)
            ['value' => '123abc', 'expected' => 123], // PHP casts this to 123
            ['value' => 'abc123', 'expected' => 0],   // PHP casts this to 0
            ['value' => true, 'expected' => 1],
            ['value' => false, 'expected' => 0],
            ['value' => null, 'expected' => 0],
        ];

        foreach ($scenarios as $index => $scenario) {
            $legacyConfigFile = $this->createTempConfigFile([
                'iface_rowamount' => $scenario['value'],
            ]);

            $result = $this->migrationManager->migrateWithCustomMapping($legacyConfigFile);

            $this->assertArrayHasKey('interface', $result);
            $this->assertArrayHasKey('rows_per_page', $result['interface']);
            $this->assertIsInt($result['interface']['rows_per_page'], "Scenario $index: Result is not integer");
            $this->assertSame(
                $scenario['expected'],
                $result['interface']['rows_per_page'],
                "Scenario $index: Expected " . var_export($scenario['expected'], true) . " for value " . var_export($scenario['value'], true)
            );

            unlink($legacyConfigFile);
        }
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
