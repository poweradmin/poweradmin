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

namespace Poweradmin\Application\Service\Factory;

use PDO;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\Application\Service\PermissionTemplateWriteService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Domain\Repository\UserAgreementRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserManagementService;
use Poweradmin\Domain\Service\UserPreferenceService;
use Poweradmin\Domain\Service\UserProfileAssembler;
use Poweradmin\Domain\Service\UserTimezoneService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\DbGroupLogger;
use Poweradmin\Infrastructure\Logger\DbUserLogger;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Poweradmin\Infrastructure\Repository\DbUserAgreementRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserPreferenceRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Psr\Log\LoggerInterface;

/**
 * Users, groups, permissions and preferences. The permission service is
 * memoized so its per-user cache spans the whole request.
 */
class UserServices
{
    private PDO $db;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private ControllerServiceFactory $services;

    private ?PermissionService $permissionService = null;
    private ?ApiPermissionService $apiPermissionService = null;
    private ?UserManagementService $userManagementService = null;
    private ?UserPreferenceService $userPreferenceService = null;
    private ?UserProvisioningService $userProvisioningService = null;

    public function __construct(PDO $db, ConfigurationInterface $config, LoggerInterface $logger, ControllerServiceFactory $services)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->services = $services;
    }

    public function userRepository(): UserRepositoryInterface
    {
        return new DbUserRepository($this->db, $this->config);
    }

    /**
     * Shared instance so the per-user permission cache spans the whole request
     */
    public function permissionService(): PermissionService
    {
        return $this->permissionService ??= new PermissionService($this->userRepository());
    }

    /**
     * The permission rules that need group or template lookups beyond PermissionService
     */
    public function apiPermissionService(): ApiPermissionService
    {
        return $this->apiPermissionService ??= new ApiPermissionService($this->db, $this->permissionService(), $this->config);
    }

    /**
     * The user service the API uses, over the request's shared permission cache
     */
    public function userManagementService(): UserManagementService
    {
        return $this->userManagementService ??= new UserManagementService(
            $this->userRepository(),
            $this->permissionService(),
            new UserProfileAssembler($this->permissionService(), $this->userGroupRepository()),
            UserAuthenticationService::fromConfig($this->config),
            new PasswordPolicyService($this->config),
            (bool)$this->config->get('ldap', 'enabled', false),
            $this->services->domainManager(),
            $this->services->zoneManagementService()
        );
    }

    public function userGroupRepository(): UserGroupRepositoryInterface
    {
        return new DbUserGroupRepository($this->db);
    }

    public function userGroupMemberRepository(): UserGroupMemberRepositoryInterface
    {
        return new DbUserGroupMemberRepository($this->db);
    }

    public function permissionTemplateRepository(): DbPermissionTemplateRepository
    {
        return new DbPermissionTemplateRepository($this->db, $this->config);
    }

    public function permissionTemplateWriteService(): PermissionTemplateWriteService
    {
        return new PermissionTemplateWriteService($this->permissionTemplateRepository(), $this->userRepository());
    }

    /**
     * Shared instance so its per-request preference cache survives across
     * consumers (pagination, timezone, and direct preference reads).
     */
    public function userPreferenceService(): UserPreferenceService
    {
        if ($this->userPreferenceService === null) {
            $repository = new DbUserPreferenceRepository($this->db);
            $this->userPreferenceService = new UserPreferenceService($repository, $this->config);
        }
        return $this->userPreferenceService;
    }

    public function userTimezoneService(): UserTimezoneService
    {
        return new UserTimezoneService($this->userPreferenceService(), $this->config);
    }

    public function paginationService(): PaginationService
    {
        return new PaginationService($this->userPreferenceService());
    }

    public function userProvisioningService(): UserProvisioningService
    {
        return $this->userProvisioningService ??= new UserProvisioningService($this->db, $this->config, $this->logger, $this->userRepository());
    }

    public function userAgreementRepository(): UserAgreementRepositoryInterface
    {
        return new DbUserAgreementRepository($this->db, $this->config);
    }

    public function userLogger(): DbUserLogger
    {
        return new DbUserLogger($this->db);
    }

    public function groupLogger(): DbGroupLogger
    {
        return new DbGroupLogger($this->db);
    }
}
