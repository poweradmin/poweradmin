<?php

use PHPUnit\Framework\TestCase;
use Poweradmin\Configuration;

class ConfigurationTest extends TestCase
{
    private string $tempDefaultConfigFile;
    private string $tempCustomConfigFile;

    protected function setUp(): void
    {
        $tempDir = sys_get_temp_dir();

        $this->tempDefaultConfigFile = $tempDir . '/config-me.inc.php';
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

        $config = new Configuration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertEquals('baz', $config->get('foo'), 'Should return the value from custom config file.');
        $this->assertEquals(42, $config->get('number'), 'Should return the value from default config file.');
        $this->assertEquals(true, $config->get('boolean'), 'Should return the boolean value from default config file.');
        $this->assertEquals('extra_value', $config->get('extra'), 'Should return the value from custom config file.');
        $this->assertEquals('LOG_USER', $config->get('logUser'), 'Should return the constant value LOG_USER from custom config file.');
        $this->assertNull($config->get('non_existing_key'), 'Should return null for non-existing keys.');
    }

    public function testEmptyConfigurationFiles()
    {
        file_put_contents($this->tempDefaultConfigFile, '<?php');
        file_put_contents($this->tempCustomConfigFile, '<?php');

        $config = new Configuration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertNull($config->get('foo'), 'Should return null for non-existing keys.');
    }

    public function testInvalidConfigurationFiles()
    {
        file_put_contents($this->tempDefaultConfigFile, '<?php @invalid = code;');
        file_put_contents($this->tempCustomConfigFile, '<?php $foo = ;');

        $config = new Configuration($this->tempDefaultConfigFile, $this->tempCustomConfigFile);

        $this->assertNull($config->get('foo'), 'Should return null for invalid configuration files.');
    }

    public function testMissingConfigurationFiles()
    {
        $config = new Configuration($this->tempDefaultConfigFile . '_nonexistent', $this->tempCustomConfigFile . '_nonexistent');

        $this->assertNull($config->get('foo'), 'Should return null for missing configuration files.');
    }
}
