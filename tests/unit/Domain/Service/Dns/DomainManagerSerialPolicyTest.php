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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\NullLogger;
use ReflectionClass;

#[CoversClass(DomainManager::class)]
class DomainManagerSerialPolicyTest extends TestCase
{
    private DomainManager $manager;
    private MockObject $backendProvider;
    private MockObject $config;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $this->reflection = new ReflectionClass(DomainManager::class);
        $this->manager = $this->reflection->newInstanceWithoutConstructor();
        $this->backendProvider = $this->createMock(DnsBackendProvider::class);
        $this->config = $this->createMock(ConfigurationManager::class);

        $this->setProperty('backendProvider', $this->backendProvider);
        $this->setProperty('config', $this->config);
        $this->setProperty('logger', new NullLogger());
    }

    private function setProperty(string $name, mixed $value): void
    {
        $property = $this->reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($this->manager, $value);
    }

    private function applySerialPolicy(?string $soaEditApi): void
    {
        $method = $this->reflection->getMethod('applySerialPolicy');
        $method->setAccessible(true);
        $method->invoke($this->manager, 7, 'example.com', $soaEditApi);
    }

    private function configureDefaults(string $soaEditApi, string $soaEdit): void
    {
        $this->config->method('get')->willReturnMap([
            ['dns', 'soa_edit_api', '', $soaEditApi],
            ['dns', 'soa_edit', '', $soaEdit],
        ]);
    }

    public function testExplicitValueWinsOverConfigDefault(): void
    {
        $this->configureDefaults('INCREASE', '');
        $this->backendProvider->expects($this->once())
            ->method('setZoneSerialPolicy')
            ->with(7, 'example.com', ['soa_edit_api' => 'EPOCH'])
            ->willReturn(true);

        $this->applySerialPolicy('EPOCH');
    }

    public function testOffClearsThePolicy(): void
    {
        $this->configureDefaults('', '');
        $this->backendProvider->expects($this->once())
            ->method('setZoneSerialPolicy')
            ->with(7, 'example.com', ['soa_edit_api' => ''])
            ->willReturn(true);

        $this->applySerialPolicy('OFF');
    }

    public function testConfigDefaultsApplyWhenNoExplicitChoice(): void
    {
        $this->configureDefaults('INCREASE', 'INCEPTION-INCREMENT');
        $this->backendProvider->expects($this->once())
            ->method('setZoneSerialPolicy')
            ->with(7, 'example.com', ['soa_edit_api' => 'INCREASE', 'soa_edit' => 'INCEPTION-INCREMENT'])
            ->willReturn(true);

        $this->applySerialPolicy(null);
    }

    public function testNothingHappensWithoutPolicyOrDefaults(): void
    {
        $this->configureDefaults('', '');
        $this->backendProvider->expects($this->never())->method('setZoneSerialPolicy');

        $this->applySerialPolicy(null);
    }

    public function testInvalidValuesAreIgnored(): void
    {
        $this->configureDefaults('BOGUS', 'ALSO-BOGUS');
        $this->backendProvider->expects($this->never())->method('setZoneSerialPolicy');

        $this->applySerialPolicy(null);
    }

    public function testBackendExceptionDoesNotPropagate(): void
    {
        $this->expectNotToPerformAssertions();

        $this->configureDefaults('', '');
        $this->backendProvider->method('setZoneSerialPolicy')
            ->willThrowException(new \RuntimeException('API down'));

        $this->applySerialPolicy('EPOCH');
    }
}
