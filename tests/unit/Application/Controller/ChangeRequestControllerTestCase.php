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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request as HttpRequest;
use Poweradmin\Application\Service\ChangeApprovalContext;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Shared fixture for the change request controllers: a runtime configuration
 * with approval.enabled switchable, a stub service factory, and a request
 * environment that bypasses the session bootstrap.
 */
abstract class ChangeRequestControllerTestCase extends TestCase
{
    protected const USER_ID = 7;

    private array $configBackup = [];
    private bool $configInitializedBackup = false;
    private ?string $previousRequestMethod = null;
    private array $previousGet = [];
    private array $previousPost = [];

    /** @var ControllerServiceFactory&MockObject */
    protected ControllerServiceFactory $factory;
    /** @var PermissionService&MockObject */
    protected PermissionService $permissions;
    /** @var MessageService&MockObject */
    protected MessageService $messages;

    protected function setUp(): void
    {
        parent::setUp();
        [$settings, $initialized] = self::configProperties();
        $config = ConfigurationManager::getInstance();
        $this->configBackup = $settings->getValue($config);
        $this->configInitializedBackup = $initialized->getValue($config);

        $this->previousRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $this->previousGet = $_GET;
        $this->previousPost = $_POST;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_POST = [];

        $this->factory = $this->createMock(ControllerServiceFactory::class);
        $this->permissions = $this->createMock(PermissionService::class);
        $this->messages = $this->createMock(MessageService::class);
        $this->factory->method('permissionService')->willReturn($this->permissions);
    }

    protected function tearDown(): void
    {
        [$settings, $initialized] = self::configProperties();
        $config = ConfigurationManager::getInstance();
        $settings->setValue($config, $this->configBackup);
        $initialized->setValue($config, $this->configInitializedBackup);

        if ($this->previousRequestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->previousRequestMethod;
        }
        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
        parent::tearDown();
    }

    /** @return array{0: \ReflectionProperty, 1: \ReflectionProperty} */
    private static function configProperties(): array
    {
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $settings = $reflection->getProperty('settings');
        $settings->setAccessible(true);
        $initialized = $reflection->getProperty('initialized');
        $initialized->setAccessible(true);

        return [$settings, $initialized];
    }

    protected function configure(bool $approvalEnabled): ConfigurationManager
    {
        [$settings, $initialized] = self::configProperties();
        $config = ConfigurationManager::getInstance();
        $settings->setValue($config, [
            'database' => ['type' => 'sqlite'],
            'security' => ['global_token_validation' => true],
            'interface' => ['rows_per_page' => 10],
            'approval' => ['enabled' => $approvalEnabled, 'require_review_for_all' => false],
        ]);
        $initialized->setValue($config, true);

        return $config;
    }

    protected function environment(ConfigurationManager $config): ControllerEnvironment
    {
        $csrf = $this->createMock(CsrfTokenService::class);
        $csrf->method('validateToken')->willReturn(true);

        $user = $this->createMock(UserContextService::class);
        $user->method('getLoggedInUserId')->willReturn(self::USER_ID);
        $user->method('getLoggedInUsername')->willReturn('reviewer');
        $user->method('isAuthenticated')->willReturn(true);

        // The real context over the stubbed factory, so approval answers follow
        // whatever permission and repository doubles a test plants
        $this->factory->method('changeApprovalContext')->willReturn(new ChangeApprovalContext(
            $config,
            fn() => $this->factory->permissionService(),
            fn() => $this->factory->zoneRepository(),
            fn() => $this->factory->zoneChangeRequestRepository()
        ));

        return new ControllerEnvironment(
            $config,
            $this->createMock(PDO::class),
            new NullLogger(),
            $this->factory,
            new HttpRequest(),
            $csrf,
            $this->messages,
            $user
        );
    }

    protected function post(array $fields): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $fields + ['_token' => 'tok'];
    }

    protected function pendingRequest(int $id = 5, int $zoneId = 42, int $requesterId = 3, string $status = ZoneChangeRequest::STATUS_PENDING): ZoneChangeRequest
    {
        return new ZoneChangeRequest(
            $id,
            $zoneId,
            'example.com',
            ZoneChangeRequest::KIND_RECORDS,
            $status,
            $requesterId,
            'requester',
            'because',
            '2026010101',
            [[
                'op' => ZoneChangeRequest::OP_EDIT,
                'record_id' => '9',
                'before' => ['id' => '9', 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => false, 'comment' => null, 'zone_name' => 'example.com'],
                'after' => ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 7200, 'prio' => 0, 'disabled' => 0, 'comment' => ''],
            ]],
            null,
            null,
            null,
            null,
            '2026-01-01 10:00:00',
            null,
            null,
            null
        );
    }

    /**
     * Grants or withholds the reviewer role for every zone.
     */
    protected function reviewer(bool $canReview): void
    {
        $this->permissions->method('getChangeApprovePermissionLevelForZone')->willReturn($canReview ? 'all' : 'none');
        $this->permissions->method('getChangeApprovePermissionLevel')->willReturn($canReview ? 'all' : 'none');
        $this->permissions->method('getEditPermissionLevelForZone')->willReturn($canReview ? 'all' : 'none');
        $this->permissions->method('getEditPermissionLevel')->willReturn($canReview ? 'all' : 'none');
        $this->permissions->method('userOwnsZone')->willReturn(false);
    }
}
