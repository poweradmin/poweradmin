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

use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Http\Request as HttpRequest;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Application\Module\ModuleRegistry;
use PDO;
use Psr\Log\NullLogger;

/**
 * Every site where BaseController used to call exit now writes its response
 * and throws RequestHalted. These tests pin what is on the wire before the halt:
 * the full error page (header, message, footer) or the JSON body, in that order.
 */
class BaseControllerHaltTest extends SeamControllerTestCase
{
    private ?string $acceptBackup = null;
    private ?string $uriBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acceptBackup = $_SERVER['HTTP_ACCEPT'] ?? null;
        $this->uriBackup = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_SERVER['REQUEST_URI'] = '/zones/forward';
    }

    protected function tearDown(): void
    {
        $this->restoreServer('HTTP_ACCEPT', $this->acceptBackup);
        $this->restoreServer('REQUEST_URI', $this->uriBackup);
        parent::tearDown();
    }

    private function restoreServer(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }

    private function controller(array $request = []): HaltingSeamController
    {
        $environment = $this->environment($this->configure());
        $this->factory->method('permissionService')->willReturn($this->createStub(PermissionService::class));
        $this->factory->method('auditService')->willReturn($this->createStub(AuditService::class));

        return new HaltingSeamController($request, true, $environment);
    }

    /**
     * Runs $action, capturing what it writes, and returns the halt with the output.
     *
     * @return array{0: RequestHalted, 1: string}
     */
    private function capture(callable $action): array
    {
        ob_start();
        try {
            $action();
        } catch (RequestHalted $halt) {
            return [$halt, (string) ob_get_clean()];
        }
        ob_end_clean();
        $this->fail('Expected the controller to halt');
    }

    private function assertFullErrorPage(string $output, string $message): void
    {
        $this->assertStringStartsWith('<!doctype html>', $output);
        $this->assertStringEndsWith("</html>\n", $output);
        $this->assertSame(1, substr_count($output, $message), 'The error is rendered once, in the page body');
    }

    public function testCheckConditionRendersTheErrorPageThenHalts(): void
    {
        $controller = $this->controller();

        [$halt, $output] = $this->capture(fn() => $controller->checkCondition(true, 'Zone 7 is gone.'));

        $this->assertSame(RequestHalted::KIND_CONDITION, $halt->kind);
        $this->assertSame('Zone 7 is gone.', $halt->target);
        $this->assertFullErrorPage($output, 'Zone 7 is gone.');
    }

    public function testCheckConditionPassesSilentlyWhenFalse(): void
    {
        $controller = $this->controller();

        ob_start();
        $controller->checkCondition(false, 'unused');
        $this->assertSame('', ob_get_clean());
    }

    public function testShowErrorRendersTheErrorPageThenHalts(): void
    {
        $controller = $this->controller();

        [$halt, $output] = $this->capture(fn() => $controller->showError('Bad input.', 'www'));

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame('Bad input. (Record: www)', $halt->target);
        $this->assertFullErrorPage($output, 'Bad input. (Record: www)');
    }

    public function testShowErrorAnswersJsonCallersWithABodyThenHalts(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $controller = $this->controller();

        [$halt, $output] = $this->capture(fn() => $controller->showError('Bad input.'));

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame('{"error":true,"message":"Bad input."}', $output);
    }

    public function testCheckPermissionRendersTheErrorPageThenHalts(): void
    {
        $controller = $this->controller();

        [$halt, $output] = $this->capture(fn() => $controller->checkPermission('zone_content_edit_others', 'Not allowed.'));

        $this->assertSame(RequestHalted::KIND_PERMISSION, $halt->kind);
        $this->assertSame('Not allowed.', $halt->target);
        $this->assertFullErrorPage($output, 'Not allowed.');
    }

    public function testCheckPermissionAnswersJsonCallersWithABodyThenHalts(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $controller = $this->controller();

        [$halt, $output] = $this->capture(fn() => $controller->checkPermission('zone_content_edit_others', 'Not allowed.'));

        $this->assertSame(RequestHalted::KIND_PERMISSION, $halt->kind);
        $this->assertSame('{"error":true,"message":"Not allowed."}', $output);
    }

    public function testARefusedPermissionIsAuditedWithTheRequestUri(): void
    {
        $config = $this->configure();
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logAccessDenied')->with('zone_content_edit_others', '/zones/7/edit');
        $this->factory->method('permissionService')->willReturn($this->createStub(PermissionService::class));
        $this->factory->method('auditService')->willReturn($audit);
        $environment = new ControllerEnvironment(
            $config,
            $this->createMock(PDO::class),
            new NullLogger(),
            new ModuleRegistry($config),
            $this->factory,
            new HttpRequest([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/zones/7/edit']),
            null,
            $this->messageService,
            new UserContextService()
        );
        $controller = new HaltingSeamController([], true, $environment);

        [$halt] = $this->capture(fn() => $controller->checkPermission('zone_content_edit_others', 'Not allowed.'));

        $this->assertSame(RequestHalted::KIND_PERMISSION, $halt->kind);
    }

    public function testRedirectSendsNothingToTheBodyAndHaltsWithTheFinalUrl(): void
    {
        $controller = $this->controller();

        [$halt, $output] = $this->capture(fn() => $controller->redirect('/zones/forward', ['start' => 2]));

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/zones/forward?start=2', $halt->target);
        $this->assertSame('', $output);
    }

    public function testRedirectPrependsTheBaseUrlPrefix(): void
    {
        $environment = $this->environment($this->configure(['interface' => ['base_url_prefix' => '/dns']]));
        $controller = new HaltingSeamController([], true, $environment);

        [$halt] = $this->capture(fn() => $controller->redirect('/zones/forward'));

        $this->assertSame('/dns/zones/forward', $halt->target);
    }

    public function testInvalidCsrfTokenOnPostRendersTheErrorPageThenHalts(): void
    {
        $this->post(['_token' => 'wrong']);
        $csrf = $this->createMock(CsrfTokenService::class);
        $csrf->method('validateToken')->willReturn(false);
        $config = $this->configure();
        $environment = new ControllerEnvironment(
            $config,
            $this->createMock(PDO::class),
            new NullLogger(),
            new ModuleRegistry($config),
            $this->factory,
            $this->request(),
            $csrf,
            $this->messageService,
            new UserContextService()
        );

        [$halt, $output] = $this->capture(fn() => new HaltingSeamController(['_token' => 'wrong'], true, $environment));

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame('Invalid CSRF token.', $halt->target);
        // The error is flashed after the header went out, so this page shows none of it
        $this->assertStringStartsWith('<!doctype html>', $output);
        $this->assertStringEndsWith("</html>\n", $output);
        $this->assertStringNotContainsString('Invalid CSRF token.', $output);
        $this->assertSame([['error', 'Invalid CSRF token.']], $this->messagesFor('system'));
    }
}
