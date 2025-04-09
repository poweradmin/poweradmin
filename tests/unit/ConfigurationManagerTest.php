<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;

class ConfigurationManagerTest extends TestCase
{
    private string $tempDefaultConfigFile;
    private string $tempNewConfigFile;

    protected function setUp(): void
    {
        $tempDir = sys_get_temp_dir();
        $this->tempDefaultConfigFile = $tempDir . '/settings.defaults.php';
        $this->tempNewConfigFile = $tempDir . '/settings.php';

        $this->resetConfigurationManager();
    }

    protected function tearDown(): void
    {
        @unlink($this->tempDefaultConfigFile);
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
     * Mock the ConfigurationManager with specific settings
     *
     * @param array $settings The settings to use
     * @return void
     */
    private function mockConfigurationManager(array $settings)
    {
        $configManager = ConfigurationManager::getInstance();

        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $reflectionClass->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $settingsProperty->setValue($configManager, $settings);

        $initializedProperty = $reflectionClass->getProperty('initialized');
        $initializedProperty->setAccessible(true);
        $initializedProperty->setValue($configManager, true);
    }

    public function testGetConfigValue()
    {
        // Mock the configuration manager with test values
        $this->mockConfigurationManager([
            'database' => ['name' => 'test_db', 'host' => 'localhost'],
            'security' => ['session_key' => 'test_key'],
            'misc' => ['display_stats' => true]
        ]);

        $config = ConfigurationManager::getInstance();

        // Test simple values
        $this->assertEquals('test_db', $config->get('database', 'name'), 'Should return correct database name value.');
        $this->assertEquals('localhost', $config->get('database', 'host'), 'Should return correct host value.');
        $this->assertEquals('test_key', $config->get('security', 'session_key'), 'Should return correct session key.');
        $this->assertTrue($config->get('misc', 'display_stats'), 'Should return correct boolean value.');
        
        // Test non-existing values
        $this->assertNull($config->get('database', 'non_existing_key'), 'Should return null for non-existing database key.');
        $this->assertNull($config->get('non_existing_group', 'key'), 'Should return null for non-existing group.');
    }

    public function testGetConfigGroupValues()
    {
        // Mock the configuration manager with test values
        $this->mockConfigurationManager([
            'database' => ['name' => 'test_db', 'host' => 'localhost', 'port' => 3306],
            'security' => ['session_key' => 'test_key', 'password_encryption' => 'bcrypt'],
            'empty_group' => []
        ]);

        $config = ConfigurationManager::getInstance();

        // Test getting entire group
        $databaseGroup = $config->getGroup('database');
        $this->assertIsArray($databaseGroup, 'Should return array for database group.');
        $this->assertCount(3, $databaseGroup, 'Database group should have 3 items.');
        $this->assertEquals('test_db', $databaseGroup['name'], 'Database group should contain correct name.');
        $this->assertEquals('localhost', $databaseGroup['host'], 'Database group should contain correct host.');
        $this->assertEquals(3306, $databaseGroup['port'], 'Database group should contain correct port.');

        // Test getting security group
        $securityGroup = $config->getGroup('security');
        $this->assertIsArray($securityGroup, 'Should return array for security group.');
        $this->assertCount(2, $securityGroup, 'Security group should have 2 items.');
        $this->assertEquals('test_key', $securityGroup['session_key'], 'Security group should contain correct session_key.');
        $this->assertEquals('bcrypt', $securityGroup['password_encryption'], 'Security group should contain correct password_encryption.');

        // Test empty group
        $emptyGroup = $config->getGroup('empty_group');
        $this->assertIsArray($emptyGroup, 'Should return empty array for empty group.');
        $this->assertEmpty($emptyGroup, 'Empty group should be empty.');

        // Test non-existing group
        $nonExistingGroup = $config->getGroup('non_existing_group');
        $this->assertIsArray($nonExistingGroup, 'Should return empty array for non-existing group.');
        $this->assertEmpty($nonExistingGroup, 'Non-existing group should be empty.');
    }

    public function testGetAllConfigValues()
    {
        // Mock the configuration manager with test values
        $this->mockConfigurationManager([
            'database' => ['name' => 'test_db', 'host' => 'localhost'],
            'security' => ['session_key' => 'test_key'],
            'misc' => ['display_stats' => true]
        ]);

        $config = ConfigurationManager::getInstance();
        $allSettings = $config->getAll();

        $this->assertIsArray($allSettings, 'GetAll should return an array.');
        $this->assertArrayHasKey('database', $allSettings, 'Settings should contain database group.');
        $this->assertArrayHasKey('security', $allSettings, 'Settings should contain security group.');
        $this->assertArrayHasKey('misc', $allSettings, 'Settings should contain misc group.');
        
        $this->assertEquals('test_db', $allSettings['database']['name'], 'Should contain correct database name.');
        $this->assertEquals('localhost', $allSettings['database']['host'], 'Should contain correct host.');
        $this->assertEquals('test_key', $allSettings['security']['session_key'], 'Should contain correct session key.');
        $this->assertTrue($allSettings['misc']['display_stats'], 'Should contain correct display stats value.');
    }

    public function testNestedKeyAccess()
    {
        // Mock the configuration manager with nested values
        $this->mockConfigurationManager([
            'complex' => [
                'nested' => [
                    'value' => 'nested_value'
                ],
                'array' => [1, 2, 3],
                'simple' => 'simple_value'
            ]
        ]);

        $config = ConfigurationManager::getInstance();

        // Test accessing nested key with dot notation
        $this->assertEquals('nested_value', $config->get('complex', 'nested.value'), 'Should access nested value with dot notation.');
        $this->assertEquals('simple_value', $config->get('complex', 'simple'), 'Should access simple value.');
        
        // Try accessing non-existing nested paths
        $this->assertNull($config->get('complex', 'nested.non_existing'), 'Should return null for non-existing nested key.');
        $this->assertNull($config->get('complex', 'non_existing.value'), 'Should return null for non-existing nested path.');
        
        // Access array values
        $this->assertEquals([1, 2, 3], $config->get('complex', 'array'), 'Should return array value correctly.');
    }

    public function testConvertLegacyConfig()
    {
        // Create a legacy configuration array
        $legacyConfig = [
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_user' => 'testuser',
            'db_pass' => 'testpass',
            'db_name' => 'testdb',
            'pdns_db_name' => 'pdnsdb',
            'session_key' => 'testsecret',
            'password_encryption' => 'bcrypt',
            'password_encryption_cost' => 10,
            'iface_lang' => 'en_EN',
            'iface_style' => 'ignite',
            'dns_hostmaster' => 'hostmaster@example.com',
            'dns_ns1' => 'ns1.example.com',
            'dns_ttl' => 86400,
            'dns_soa' => '28800 7200 604800 86400',
            'pdnssec_use' => true,
            'syslog_use' => true
        ];

        // Create a ConfigurationManager instance
        $config = ConfigurationManager::getInstance();

        // Use reflection to access the private method
        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $method = $reflectionClass->getMethod('convertLegacyConfig');
        $method->setAccessible(true);

        // Call the private method
        $newConfig = $method->invoke($config, $legacyConfig);

        // Verify conversion to new format
        $this->assertEquals('localhost', $newConfig['database']['host']);
        $this->assertEquals('3306', $newConfig['database']['port']);
        $this->assertEquals('testuser', $newConfig['database']['user']);
        $this->assertEquals('testpass', $newConfig['database']['password']);
        $this->assertEquals('testdb', $newConfig['database']['name']);
        $this->assertEquals('pdnsdb', $newConfig['database']['pdns_name']);
        
        $this->assertEquals('testsecret', $newConfig['security']['session_key']);
        $this->assertEquals('bcrypt', $newConfig['security']['password_encryption']);
        $this->assertEquals(10, $newConfig['security']['password_cost']);
        
        $this->assertEquals('en_EN', $newConfig['interface']['language']);
        $this->assertEquals('ignite', $newConfig['interface']['theme']);
        
        $this->assertEquals('hostmaster@example.com', $newConfig['dns']['hostmaster']);
        $this->assertEquals('ns1.example.com', $newConfig['dns']['ns1']);
        $this->assertEquals(86400, $newConfig['dns']['ttl']);
        
        // Check that SOA values were properly parsed
        $this->assertEquals(28800, $newConfig['dns']['soa_refresh']);
        $this->assertEquals(7200, $newConfig['dns']['soa_retry']);
        $this->assertEquals(604800, $newConfig['dns']['soa_expire']);
        $this->assertEquals(86400, $newConfig['dns']['soa_minimum']);
        
        $this->assertTrue($newConfig['dnssec']['enabled']);
        $this->assertTrue($newConfig['logging']['syslog_enabled']);
    }
}