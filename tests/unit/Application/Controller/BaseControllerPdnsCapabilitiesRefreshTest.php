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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Model\PdnsCapabilities;
use ReflectionClass;

/**
 * Covers BaseController::getPdnsCapabilities() re-detecting the PowerDNS
 * version once the session cache expires. Without that, every capability
 * gate (catalog zones, Primary/Secondary naming, newer record types) went
 * dark five minutes after the last dashboard visit (issue #1564).
 */
class BaseControllerPdnsCapabilitiesRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SESSION['pdns_server_info'], $_SESSION['pdns_version_last_attempt']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['pdns_server_info'], $_SESSION['pdns_version_last_attempt']);
        parent::tearDown();
    }

    public function testFreshCacheIsUsedWithoutRefresh(): void
    {
        $this->cacheVersion('4.9.0', time());
        $controller = $this->controllerRefreshingTo('5.1.4');

        $caps = $this->capabilities($controller);

        $this->assertSame(0, $controller->refreshCalls);
        $this->assertSame('4.9.0', $caps->version());
    }

    public function testExpiredCacheTriggersRefreshAndUsesTheNewVersion(): void
    {
        $this->cacheVersion('4.9.0', time() - 301);
        $controller = $this->controllerRefreshingTo('5.1.4');

        $caps = $this->capabilities($controller);

        $this->assertSame(1, $controller->refreshCalls);
        $this->assertSame('5.1.4', $caps->version());
        $this->assertTrue($caps->supportsCatalogZones());
    }

    public function testMissingCacheTriggersRefresh(): void
    {
        $controller = $this->controllerRefreshingTo('4.7.3');

        $caps = $this->capabilities($controller);

        $this->assertSame(1, $controller->refreshCalls);
        $this->assertTrue($caps->supportsCatalogZones());
    }

    public function testFailedRefreshKeepsTheExpiredVersion(): void
    {
        $this->cacheVersion('4.9.0', time() - 301);
        $controller = $this->controllerRefreshingTo(null);

        $caps = $this->capabilities($controller);

        $this->assertSame(1, $controller->refreshCalls);
        $this->assertSame('4.9.0', $caps->version());
        $this->assertTrue($caps->supportsCatalogZones());
    }

    public function testFailedRefreshWithNoCacheLeavesVersionUnknown(): void
    {
        $controller = $this->controllerRefreshingTo(null);

        $caps = $this->capabilities($controller);

        $this->assertSame(1, $controller->refreshCalls);
        $this->assertFalse($caps->isKnown());
        $this->assertFalse($caps->supportsCatalogZones());
    }

    private function capabilities(BaseController $controller): PdnsCapabilities
    {
        $method = (new ReflectionClass(BaseController::class))->getMethod('getPdnsCapabilities');
        $method->setAccessible(true);
        return $method->invoke($controller);
    }

    /**
     * A controller whose refresh writes $version to the session cache the way
     * PdnsVersionService::detect() would, or leaves it untouched when null.
     */
    private function controllerRefreshingTo(?string $version): PdnsCapabilitiesRefreshTestController
    {
        $controller = (new ReflectionClass(PdnsCapabilitiesRefreshTestController::class))->newInstanceWithoutConstructor();
        $controller->versionToCache = $version;
        return $controller;
    }

    private function cacheVersion(string $version, int $fetchedAt): void
    {
        $_SESSION['pdns_server_info'] = [
            'fetched_at' => $fetchedAt,
            'info' => ['version' => $version, 'daemon_type' => 'authoritative', 'id' => 'localhost'],
        ];
    }
}
