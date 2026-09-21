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
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\NullLogger;

/**
 * The ControllerEnvironment seam lets a test construct any controller without a
 * configuration file, database connection or session: the base constructor takes
 * the environment's collaborators as-is and the create*() accessors route through
 * the supplied service factory.
 */
class ControllerEnvironmentSeamTest extends TestCase
{
    private ?string $previousRequestMethod = null;

    protected function setUp(): void
    {
        $this->previousRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        if ($this->previousRequestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->previousRequestMethod;
        }
    }

    private function makeController(?ControllerServiceFactory $factory = null, array $request = []): TestableSeamController
    {
        $environment = new ControllerEnvironment(
            ConfigurationManager::getInstance(),
            $this->createMock(PDO::class),
            new NullLogger(),
            $factory,
            null,
            null,
            null,
            $this->createMock(UserContextService::class)
        );

        return new TestableSeamController($request, true, $environment);
    }

    public function testConstructsWithoutConfigFileDatabaseOrSession(): void
    {
        $controller = $this->makeController();

        $this->assertInstanceOf(BaseController::class, $controller);
        $this->assertSame(ConfigurationManager::getInstance(), $controller->getConfig());
    }

    public function testCreateAccessorsRouteThroughTheSuppliedFactory(): void
    {
        $pagination = $this->createMock(PaginationService::class);
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->expects($this->once())->method('paginationService')->willReturn($pagination);

        $controller = $this->makeController($factory);

        $this->assertSame($pagination, $controller->paginationServiceForTest());
    }

    public function testRequestDataIsTakenVerbatim(): void
    {
        $controller = $this->makeController(null, ['id' => '5', 'page' => 'edit']);

        $this->assertSame('5', $controller->getRequest()['id']);
    }

    private function makeCsrfEnvironment(CsrfTokenService $csrf): ControllerEnvironment
    {
        return new ControllerEnvironment(
            ConfigurationManager::getInstance(),
            $this->createMock(PDO::class),
            new NullLogger(),
            null,
            null,
            $csrf,
            null,
            $this->createMock(UserContextService::class)
        );
    }

    public function testPostConstructionValidatesTheCsrfToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $csrf = $this->createMock(CsrfTokenService::class);
        $csrf->expects($this->once())->method('validateToken')->with('tok')->willReturn(true);
        $environment = $this->makeCsrfEnvironment($csrf);

        new class (['_token' => 'tok'], true, $environment) extends BaseController {
            public function run(): void
            {
            }
        };
    }

    public function testCsrfOptOutSkipsValidationOnPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $csrf = $this->createMock(CsrfTokenService::class);
        $csrf->expects($this->never())->method('validateToken');
        $environment = $this->makeCsrfEnvironment($csrf);

        new class (['_token' => 'tok'], true, $environment) extends BaseController {
            public function run(): void
            {
            }

            protected function requiresCsrfValidation(): bool
            {
                return false;
            }
        };
    }
}
