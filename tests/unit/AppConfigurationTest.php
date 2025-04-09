<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;
use ReflectionProperty;

class AppConfigurationTest extends TestCase
{
    private string $tempDefaultConfigFile;
    private string $tempCustomConfigFile;
    private string $tempNewConfigFile;

    protected function setUp(): void
    {
        $tempDir = sys_get_temp_dir();

        $this->tempDefaultConfigFile = $tempDir . '/config-defaults.inc.php';
        $this->tempCustomConfigFile = $tempDir . '/config.inc.php';
        $this->tempNewConfigFile = $tempDir . '/settings.php';

        $this->resetConfigurationManager();
    }

    protected function tearDown(): void
    {
        @unlink($this->tempDefaultConfigFile);
        @unlink($this->tempCustomConfigFile);
        @unlink($this->tempNewConfigFile);

        $this->resetConfigurationManager();
    }

    /**
     * Reset the ConfigurationManager singleton between tests
     */
    private function resetConfigurationManager()
    {
        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $instanceProperty = $reflectionClass->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);

        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $initializedProperty = $reflectionClass->getProperty('initialized');
        $initializedProperty->setAccessible(true);
        $initializedProperty->setValue(ConfigurationManager::getInstance(), false);
    }

    /**
     * Mock the ConfigurationManager to use our test files
     *
     * @param array $config The configuration to use
     * @return void
     */
    private function mockConfigurationManager(array $config)
    {
        $configManager = ConfigurationManager::getInstance();

        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $reflectionClass->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $settingsProperty->setValue($configManager, $config);

        $initializedProperty = $reflectionClass->getProperty('initialized');
        $initializedProperty->setAccessible(true);
        $initializedProperty->setValue($configManager, true);
    }

    public function testGetConfigValue()
    {
        // Mock the configuration manager with test values
        $this->mockConfigurationManager([
            'database' => ['name' => 'test_db'],
            'security' => ['session_key' => 'test_key'],
            'misc' => ['display_stats' => true]
        ]);

        $config = new AppConfiguration();

        // Test converted legacy format values
        $this->assertEquals('test_db', $config->get('db_name'), 'Should convert new format to legacy format.');
        $this->assertEquals('test_key', $config->get('session_key'), 'Should convert new format to legacy format.');
        $this->assertTrue($config->get('display_stats'), 'Should convert new format to legacy format.');
        $this->assertNull($config->get('non_existing_key'), 'Should return null for non-existing keys.');
    }

    public function testGetConfigValueWithDefault()
    {
        // Mock the configuration manager with test values
        $this->mockConfigurationManager([
            'database' => ['name' => 'test_db'],
        ]);

        $config = new AppConfiguration();

        $this->assertEquals('test_db', $config->get('db_name', 'default_db'), 'Should return the actual value, not the default.');
        $this->assertEquals('default_value', $config->get('non_existing_key', 'default_value'), 'Should return default for non-existing keys.');
        $this->assertEquals(100, $config->get('non_existing_number', 100), 'Should return the default integer value for non-existing keys.');
        $this->assertTrue($config->get('non_existing_boolean', true), 'Should return the default boolean value for non-existing keys.');
        $this->assertEquals(['key' => 'value'], $config->get('non_existing_array', ['key' => 'value']), 'Should return the default array value for non-existing keys.');
    }

    public function testGetAllConfig()
    {
        // Mock the configuration manager with test values
        $this->mockConfigurationManager([
            'database' => ['name' => 'test_db', 'host' => 'localhost'],
            'security' => ['session_key' => 'secret_key'],
        ]);

        $config = new AppConfiguration();

        $allConfig = $config->getAll();
        $this->assertIsArray($allConfig, 'GetAll should return an array.');
        $this->assertArrayHasKey('db_name', $allConfig, 'Legacy format should contain db_name.');
        $this->assertArrayHasKey('db_host', $allConfig, 'Legacy format should contain db_host.');
        $this->assertArrayHasKey('session_key', $allConfig, 'Legacy format should contain session_key.');
    }

    public function testConvertToLegacyFormat()
    {
        // Create a test configuration in the new format
        $newConfig = [
            'database' => [
                'host' => 'localhost',
                'port' => '3306',
                'user' => 'testuser',
                'password' => 'testpass',
                'name' => 'testdb',
            ],
            'security' => [
                'session_key' => 'testsecret',
                'password_encryption' => 'bcrypt',
            ],
            'interface' => [
                'language' => 'en_EN',
                'theme' => 'ignite',
            ],
            'dns' => [
                'soa_refresh' => 28800,
                'soa_retry' => 7200,
                'soa_expire' => 604800,
                'soa_minimum' => 86400,
            ],
        ];

        // Create an AppConfiguration instance
        $config = new AppConfiguration();

        // Use reflection to access the private method
        $reflectionClass = new ReflectionClass(AppConfiguration::class);
        $method = $reflectionClass->getMethod('convertToLegacyFormat');
        $method->setAccessible(true);

        // Call the private method
        $legacyConfig = $method->invoke($config, $newConfig);

        // Verify conversion to legacy format
        $this->assertEquals('localhost', $legacyConfig['db_host']);
        $this->assertEquals('3306', $legacyConfig['db_port']);
        $this->assertEquals('testuser', $legacyConfig['db_user']);
        $this->assertEquals('testpass', $legacyConfig['db_pass']);
        $this->assertEquals('testdb', $legacyConfig['db_name']);
        $this->assertEquals('testsecret', $legacyConfig['session_key']);
        $this->assertEquals('bcrypt', $legacyConfig['password_encryption']);
        $this->assertEquals('en_EN', $legacyConfig['iface_lang']);
        $this->assertEquals('ignite', $legacyConfig['iface_style']);
        $this->assertEquals('28800 7200 604800 86400', $legacyConfig['dns_soa']);
    }
}
