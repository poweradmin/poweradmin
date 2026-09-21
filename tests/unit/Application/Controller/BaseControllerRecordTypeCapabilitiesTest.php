<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Application\Controller;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Service\Dns\RecordTypeService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Covers BaseController::getRecordTypeCapabilities(), which decides whether
 * record-type dropdowns are version-filtered. Only API backends report a
 * version, so SQL backends must skip filtering (null) or valid types like
 * HTTPS/SVCB/ZONEMD vanish permanently from every record form.
 */
class BaseControllerRecordTypeCapabilitiesTest extends TestCase
{
    private array $configBackup = [];
    private bool $configInitializedBackup = false;

    protected function setUp(): void
    {
        parent::setUp();
        [$settings, $initialized] = $this->readConfigState();
        $this->configBackup = $settings;
        $this->configInitializedBackup = $initialized;
        unset($_SESSION['pdns_server_info'], $_SESSION['pdns_version_last_attempt']);
    }

    protected function tearDown(): void
    {
        $this->writeConfigState($this->configBackup, $this->configInitializedBackup);
        unset($_SESSION['pdns_server_info'], $_SESSION['pdns_version_last_attempt']);
        parent::tearDown();
    }

    public function testReturnsNullForSqlBackend(): void
    {
        $caps = $this->resolveCapabilities(['dns' => ['backend' => 'sql']]);
        $this->assertNull($caps, 'SQL backends cannot detect a version, so filtering must be skipped');
    }

    public function testReturnsNullForSqlBackendEvenWithStaleCachedVersion(): void
    {
        $this->cacheVersion('4.4.0');
        $caps = $this->resolveCapabilities(['dns' => ['backend' => 'sql']]);
        $this->assertNull($caps);
    }

    public function testReturnsKnownCapabilitiesForApiBackendWithCachedVersion(): void
    {
        $this->cacheVersion('4.8.3');
        $caps = $this->resolveCapabilities(['dns' => ['backend' => 'api']]);

        $this->assertInstanceOf(PdnsCapabilities::class, $caps);
        $this->assertTrue($caps->isKnown());
        $this->assertSame('4.8.3', $caps->version());
        $this->assertTrue($caps->supportsRecordType('ZONEMD'));
    }

    public function testReturnsNullForApiBackendWithoutCachedVersion(): void
    {
        // API backend before detection runs (or after the cache expired):
        // filtering must be skipped, or sessions that never hit the dashboard
        // refresh would lose valid record types from every selector.
        $caps = $this->resolveCapabilities(['dns' => ['backend' => 'api']]);

        $this->assertNull($caps, 'Unknown version must not strict-filter record types');
    }

    public function testSqlBackendCapabilitiesPreserveGatedRecordTypes(): void
    {
        $caps = $this->resolveCapabilities(['dns' => ['backend' => 'sql']]);

        $recordConfig = $this->createMock(\Poweradmin\Domain\Config\ConfigurationInterface::class);
        $recordConfig->method('get')->willReturn(null);
        $types = (new RecordTypeService($recordConfig))->getAllTypes($caps);

        // The user-visible guarantee: version-gated types stay selectable on SQL.
        $this->assertContains('HTTPS', $types);
        $this->assertContains('SVCB', $types);
        $this->assertContains('ZONEMD', $types);
    }

    private function resolveCapabilities(array $overrides): ?PdnsCapabilities
    {
        $controller = (new ReflectionClass(RecordTypeCapabilitiesTestController::class))
            ->newInstanceWithoutConstructor();

        $config = $this->buildConfig($overrides);
        $configProperty = (new ReflectionClass(BaseController::class))->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue($controller, $config);

        // The backend question goes through the service factory's provider
        $db = $this->createMock(PDO::class);
        $provider = $config->get('dns', 'backend') === 'api'
            ? new ApiDnsBackendProvider($this->createMock(PowerdnsApiClient::class), $db, $config, new NullLogger())
            : new SqlDnsBackendProvider($db, $config, new NullLogger());
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('dnsBackendProvider')->willReturn($provider);
        $factoryProperty = (new ReflectionClass(BaseController::class))->getProperty('serviceFactory');
        $factoryProperty->setAccessible(true);
        $factoryProperty->setValue($controller, $factory);

        // An expired or missing cache now triggers a refresh, which logs failures.
        $loggerProperty = (new ReflectionClass(BaseController::class))->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($controller, new NullLogger());

        $method = (new ReflectionClass(BaseController::class))->getMethod('getRecordTypeCapabilities');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }

    private function cacheVersion(string $version): void
    {
        $_SESSION['pdns_server_info'] = [
            'fetched_at' => time(),
            'info' => ['version' => $version, 'daemon_type' => 'authoritative', 'id' => 'localhost'],
        ];
    }

    private function buildConfig(array $overrides): ConfigurationManager
    {
        $settings = ['database' => ['type' => 'mysql']];
        foreach ($overrides as $group => $values) {
            $settings[$group] = array_merge($settings[$group] ?? [], $values);
        }
        $this->writeConfigState($settings, true);
        return ConfigurationManager::getInstance();
    }

    /**
     * @return array{0: array, 1: bool}
     */
    private function readConfigState(): array
    {
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $reflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $initializedProperty = $reflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $config = ConfigurationManager::getInstance();
        return [$settingsProperty->getValue($config), $initializedProperty->getValue($config)];
    }

    private function writeConfigState(array $settings, bool $initialized): void
    {
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $reflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $initializedProperty = $reflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $config = ConfigurationManager::getInstance();
        $settingsProperty->setValue($config, $settings);
        $initializedProperty->setValue($config, $initialized);
    }
}
