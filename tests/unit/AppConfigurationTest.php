<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;

class AppConfigurationTest extends TestCase
{
    private string $tempDefaultConfigFile;
    private string $tempCustomConfigFile;

    protected function setUp(): void
    {
        $tempDir = sys_get_temp_dir();

        $this->tempDefaultConfigFile = $tempDir . '/config-defaults.inc.php';
        $this->tempCustomConfigFile = $tempDir . '/config.inc.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->tempDefaultConfigFile);
        @unlink($this->tempCustomConfigFile);
    }

    public function testGetConfigValue()
    {
        file_put_contents($this->tempDefaultConfigFile, '<?php $foo = "bar"; $number = 42; $boolean = true;');
        file_put_contents($this->tempCustomConfigFile, '<?php $foo = "baz"; $extra = "extra_value"; $logUser = LOG_USER;');

        $config = new AppConfiguration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertEquals('baz', $config->get('foo'), 'Should return the value from custom config file.');
        $this->assertEquals(42, $config->get('number'), 'Should return the value from default config file.');
        $this->assertTrue($config->get('boolean'), 'Should return the boolean value from default config file.');
        $this->assertEquals('extra_value', $config->get('extra'), 'Should return the value from custom config file.');
        $this->assertEquals(LOG_USER, $config->get('logUser'), 'Should return the constant value LOG_USER from custom config file.');
        $this->assertNull($config->get('non_existing_key'), 'Should return null for non-existing keys.');
    }

    public function testEmptyConfigurationFiles()
    {
        file_put_contents($this->tempDefaultConfigFile, '<?php');
        file_put_contents($this->tempCustomConfigFile, '<?php');

        $config = new AppConfiguration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertNull($config->get('foo'), 'Should return null for non-existing keys.');
    }

    public function testInvalidConfigurationFiles()
    {
        file_put_contents($this->tempDefaultConfigFile, '<?php @invalid = code;');
        file_put_contents($this->tempCustomConfigFile, '<?php $foo = ;');

        $config = new AppConfiguration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertNull($config->get('foo'), 'Should return null for invalid configuration files.');
    }

    public function testMissingConfigurationFiles()
    {
        $config = new AppConfiguration($this->tempDefaultConfigFile . '_nonexistent', $this->tempCustomConfigFile . '_nonexistent');

        $this->assertNull($config->get('foo'), 'Should return null for missing configuration files.');
    }

    public function testBooleanFalseValue()
    {
        file_put_contents($this->tempDefaultConfigFile, '<?php $boolean_false = false;');
        file_put_contents($this->tempCustomConfigFile, '<?php');

        $config = new AppConfiguration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertSame(false, $config->get('boolean_false'), 'Should return the boolean false value from default config file.');
    }

    public function testConstantValueInDefaultConfigFile()
    {
        file_put_contents($this->tempDefaultConfigFile, '<?php $logUser = LOG_USER;');
        file_put_contents($this->tempCustomConfigFile, '<?php');

        $config = new AppConfiguration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertEquals(LOG_USER, $config->get('logUser'), 'Should return the constant value LOG_USER from default config file.');
    }

    public function testValueWithSurroundingWhitespace()
    {
        file_put_contents($this->tempDefaultConfigFile, '<?php $foo = "  bar  ";');
        file_put_contents($this->tempCustomConfigFile, '<?php');

        $config = new AppConfiguration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertEquals('  bar  ', $config->get('foo'), 'Should return the value with surrounding whitespace from default config file.');
    }

    public function testEmptyConfigFile()
    {
        file_put_contents($this->tempDefaultConfigFile, '');
        file_put_contents($this->tempCustomConfigFile, '');

        $config = new AppConfiguration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertNull($config->get('foo'), 'Should return null for empty configuration files.');
    }

    public function testImproperlyFormattedConfigFile()
    {
        file_put_contents($this->tempDefaultConfigFile, '<?php $foo = "bar');
        file_put_contents($this->tempCustomConfigFile, '<?php');

        $config = new AppConfiguration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertNull($config->get('foo'), 'Should return null for improperly formatted configuration files.');
    }

    public function testGetConfigValueWithDefault()
    {
        file_put_contents($this->tempDefaultConfigFile, '<?php $foo = "bar"; $number = 42; $boolean = true;');
        file_put_contents($this->tempCustomConfigFile, '<?php $foo = "baz"; $extra = "extra_value"; $logUser = LOG_USER;');

        $config = new AppConfiguration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertEquals('default_value', $config->get('non_existing_key', 'default_value'), 'Should return the default value for non-existing keys.');

        $this->assertEquals('baz', $config->get('foo', 'default_value'), 'Should return the value from custom config file, not the default value.');

        $this->assertEquals(100, $config->get('non_existing_number', 100), 'Should return the default integer value for non-existing keys.');
        $this->assertTrue($config->get('non_existing_boolean', true), 'Should return the default boolean value for non-existing keys.');
        $this->assertEquals(['key' => 'value'], $config->get('non_existing_array', ['key' => 'value']), 'Should return the default array value for non-existing keys.');
    }

    public function testParseTokenValueTrue()
    {
        $config = new AppConfiguration();
        $result = $config->parseTokenValue('true');
        $this->assertTrue($result);
    }

    public function testParseTokenValueFalse()
    {
        $config = new AppConfiguration();
        $result = $config->parseTokenValue('false');
        $this->assertFalse($result);
    }

    public function testParseTokenValueNumeric()
    {
        $config = new AppConfiguration();
        $result = $config->parseTokenValue('123');
        $this->assertSame(123, $result);

        $result = $config->parseTokenValue('123.45');
        $this->assertSame(123.45, $result);
    }

    public function testParseTokenValueConstant()
    {
        define('MY_CONSTANT', 'constant_value');
        $config = new AppConfiguration();
        $result = $config->parseTokenValue('MY_CONSTANT');
        $this->assertSame('constant_value', $result);
    }

    public function testParseTokenValueString()
    {
        $config = new AppConfiguration();
        $result = $config->parseTokenValue('"string_value"');
        $this->assertSame('string_value', $result);

        $result = $config->parseTokenValue("'string_value'");
        $this->assertSame('string_value', $result);
    }
}
