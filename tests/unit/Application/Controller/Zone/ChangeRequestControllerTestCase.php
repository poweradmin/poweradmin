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

namespace Poweradmin\Tests\Unit\Application\Controller\Zone;

use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request as HttpRequest;
use Poweradmin\Application\Module\ModuleRegistry;
use Poweradmin\Application\Service\Zone\ChangeApprovalContext;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Tests\Unit\Application\Controller\RecordingPageOutput;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * Shared fixture for the change request controllers: an in-memory configuration
 * with approval.enabled switchable, a stub service factory, and a request
 * environment that bypasses the session bootstrap.
 */
abstract class ChangeRequestControllerTestCase extends TestCase
{
    protected const USER_ID = 7;

    /** @var array<string, mixed> */
    private array $queryParams = [];
    /** @var array<string, mixed> */
    private array $postParams = [];
    private string $method = 'GET';

    /** @var ControllerServiceFactory&MockObject */
    protected ControllerServiceFactory $factory;
    /** @var PermissionService&MockObject */
    protected PermissionService $permissions;
    /** @var MessageService&MockObject */
    protected MessageService $messages;

    /** Every page the test's controllers rendered, in order */
    protected RecordingPageOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createMock(ControllerServiceFactory::class);
        $this->permissions = $this->createMock(PermissionService::class);
        $this->messages = $this->createMock(MessageService::class);
        $this->factory->method('permissionService')->willReturn($this->permissions);
        $this->output = new RecordingPageOutput();
    }

    protected function configure(bool $approvalEnabled): ConfigurationInterface
    {
        return new FakeConfiguration([
            'database' => ['type' => 'sqlite'],
            'security' => ['global_token_validation' => true],
            'interface' => ['rows_per_page' => 10],
            'approval' => ['enabled' => $approvalEnabled, 'require_review_for_all' => false],
        ]);
    }

    protected function environment(ConfigurationInterface $config): ControllerEnvironment
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

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        return new ControllerEnvironment(
            $config,
            $this->createMock(PDO::class),
            new NullLogger(),
            $registry,
            $this->factory,
            new HttpRequest($this->queryParams, $this->postParams, ['REQUEST_METHOD' => $this->method]),
            $csrf,
            $this->messages,
            $user,
            $this->output
        );
    }

    /**
     * Switches the pending request to POST; call before constructing the controller.
     *
     * @param array<string, mixed> $fields
     */
    protected function post(array $fields): void
    {
        $this->method = 'POST';
        $this->postParams = $fields + ['_token' => 'tok'];
    }

    /** @param array<string, mixed> $params */
    protected function query(array $params): void
    {
        $this->queryParams = $params;
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
