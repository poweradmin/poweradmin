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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\AppSettingsService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class AppSettingsServiceTest extends TestCase
{
    /**
     * @param array<string, array{value: string, type: string}> $rows
     */
    private function createRepository(array $rows = []): InMemoryAppSettingRepository
    {
        return new InMemoryAppSettingRepository($rows);
    }

    private function createConfig(array $groups = []): ConfigurationManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            function (string $group, string $key, mixed $default = null) use ($groups) {
                return $groups[$group][$key] ?? $default;
            }
        );
        return $config;
    }

    public function testGetStringPrefersDbOverConfig(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(['interface' => ['theme' => 'light']]),
            $this->createRepository(['interface.theme' => ['value' => 'dark', 'type' => 'string']])
        );
        $this->assertSame('dark', $service->getString('interface.theme', 'fallback'));
    }

    public function testGetStringFallsBackToConfigWhenDbMisses(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(['interface' => ['theme' => 'light']]),
            $this->createRepository()
        );
        $this->assertSame('light', $service->getString('interface.theme', 'fallback'));
    }

    public function testGetStringFallsBackToCallerDefaultWhenBothMiss(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(),
            $this->createRepository()
        );
        $this->assertSame('fallback', $service->getString('interface.theme', 'fallback'));
    }

    public function testGetIntCastsStoredString(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(),
            $this->createRepository(['dns.ttl' => ['value' => '300', 'type' => 'int']])
        );
        $this->assertSame(300, $service->getInt('dns.ttl', 86400));
    }

    public function testGetIntCastsConfigInt(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(['dns' => ['ttl' => 86400]]),
            $this->createRepository()
        );
        $this->assertSame(86400, $service->getInt('dns.ttl', 0));
    }

    public function testGetIntReturnsDefaultForNonNumericStored(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(),
            $this->createRepository(['dns.ttl' => ['value' => 'not-a-number', 'type' => 'int']])
        );
        $this->assertSame(42, $service->getInt('dns.ttl', 42));
    }

    public function testGetBoolHandlesStoredString(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(),
            $this->createRepository(['app.debug' => ['value' => 'true', 'type' => 'bool']])
        );
        $this->assertTrue($service->getBool('app.debug', false));
    }

    public function testGetBoolFalseString(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(),
            $this->createRepository(['app.debug' => ['value' => 'false', 'type' => 'bool']])
        );
        $this->assertFalse($service->getBool('app.debug', true));
    }

    public function testGetBoolHandlesConfigBool(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(['app' => ['debug' => true]]),
            $this->createRepository()
        );
        $this->assertTrue($service->getBool('app.debug', false));
    }

    public function testGetArrayDecodesJsonFromStorage(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(),
            $this->createRepository([
                'dns.top_record_types' => ['value' => '["A","AAAA","CNAME"]', 'type' => 'json'],
            ])
        );
        $this->assertSame(['A', 'AAAA', 'CNAME'], $service->getArray('dns.top_record_types'));
    }

    public function testGetArrayReturnsConfigArray(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(['dns' => ['top_record_types' => ['A', 'AAAA']]]),
            $this->createRepository()
        );
        $this->assertSame(['A', 'AAAA'], $service->getArray('dns.top_record_types'));
    }

    public function testKeyWithoutDotFallsThroughToCallerDefault(): void
    {
        $service = new AppSettingsService(
            $this->createConfig(['something' => ['foo' => 'bar']]),
            $this->createRepository()
        );
        // 'flat-key' has no dot, so we never call ConfigurationManager.
        $this->assertSame('default', $service->getString('flat-key', 'default'));
    }

    public function testReadsAreMemoizedWithinTheRequest(): void
    {
        $repository = $this->createRepository(['dns.ttl' => ['value' => '86400', 'type' => 'int']]);
        $service = new AppSettingsService($this->createConfig(), $repository);

        $service->getInt('dns.ttl');
        $service->getInt('dns.ttl');
        $service->getInt('dns.ttl');

        $this->assertSame(1, $repository->findCalls);
    }

    public function testWriteInvalidatesCachedRead(): void
    {
        $repository = $this->createRepository(['dns.ttl' => ['value' => '300', 'type' => 'int']]);
        $service = new AppSettingsService($this->createConfig(), $repository);

        $this->assertSame(300, $service->getInt('dns.ttl'));

        $service->setInt('dns.ttl', 7200);

        $this->assertSame(7200, $service->getInt('dns.ttl'));
        $this->assertSame(2, $repository->findCalls);
    }

    public function testClearRemovesValueAndInvalidatesCache(): void
    {
        $repository = $this->createRepository(['dns.ttl' => ['value' => '300', 'type' => 'int']]);
        $service = new AppSettingsService(
            $this->createConfig(['dns' => ['ttl' => 86400]]),
            $repository
        );

        $this->assertSame(300, $service->getInt('dns.ttl'));

        $service->clear('dns.ttl');

        // After clearing, the DB miss should fall through to config.
        $this->assertSame(86400, $service->getInt('dns.ttl'));
    }

    public function testSetArrayRoundTripsViaJson(): void
    {
        $repository = $this->createRepository();
        $service = new AppSettingsService($this->createConfig(), $repository);

        $service->setArray('dns.top_record_types', ['A', 'AAAA']);

        $this->assertSame(['A', 'AAAA'], $service->getArray('dns.top_record_types'));
    }

    public function testSetBoolPersistsBooleanTextRepresentation(): void
    {
        $repository = $this->createRepository();
        $service = new AppSettingsService($this->createConfig(), $repository);

        $service->setBool('app.debug', true);
        $row = $repository->find('app.debug');
        $this->assertSame(['value' => 'true', 'type' => 'bool'], $row);
        $this->assertTrue($service->getBool('app.debug'));
    }
}
