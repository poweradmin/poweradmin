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

namespace Poweradmin\Application\Service;

use Closure;
use PDO;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Module\ModuleServices;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ChangeApprovalContext;
use Poweradmin\Application\Service\ChangeRequestNotificationService;
use Poweradmin\Application\Service\DashboardStatsService;
use Poweradmin\Application\Service\DnsDataService;
use Poweradmin\Application\Service\EmailTemplateService;
use Poweradmin\Application\Service\Factory\AuthServices;
use Poweradmin\Application\Service\Factory\BackendServices;
use Poweradmin\Application\Service\Factory\RecordServices;
use Poweradmin\Application\Service\Factory\UserServices;
use Poweradmin\Application\Service\Factory\ZoneServices;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\OidcConfigurationService;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Application\Service\PasswordGenerationService;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\Application\Service\PermissionTemplateWriteService;
use Poweradmin\Application\Service\PowerdnsStatusService;
use Poweradmin\Application\Service\RecordAddService;
use Poweradmin\Application\Service\RecordEditService;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Application\Service\RecaptchaService;
use Poweradmin\Application\Service\RepositoryFactory;
use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Application\Service\UrlService;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Application\Service\ZoneCreateService;
use Poweradmin\Application\Service\ZoneOwnershipFormResolver;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\PasswordResetTokenRepositoryInterface;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\RecordTypeDefaultRepositoryInterface;
use Poweradmin\Domain\Repository\UserAgreementRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserMfaRepositoryInterface;
use Poweradmin\Domain\Repository\UsernameRecoveryRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneMetadataStoreInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\TemplateRecordLinkRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateSyncRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\BatchReverseRecordCreator;
use Poweradmin\Domain\Service\Zone\CatalogZoneService;
use Poweradmin\Domain\Service\Consistency\ConsistencyCheckerInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\Dns\RRSetReplaceService;
use Poweradmin\Domain\Service\Dns\RecordDeletionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\Dns\ZoneTemplateApplier;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\User\UserPreferenceService;
use Poweradmin\Domain\Service\User\UserTimezoneService;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestService;
use Poweradmin\Domain\Service\Zone\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\Zone\ZoneEditService;
use Poweradmin\Domain\Service\Auth\ZoneListPermissionService;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Zone\ZoneMetadataService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneSigningService;
use Poweradmin\Domain\Service\Zone\ZoneSortingService;
use Poweradmin\Domain\Service\Template\ZoneTemplateAccessPolicy;
use Poweradmin\Domain\Service\Template\ZoneTemplateRecordService;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Domain\Service\Template\ZoneTemplateWriteService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\AuditLogWriter;
use Poweradmin\Infrastructure\Logger\DbApiLogger;
use Poweradmin\Infrastructure\Logger\DbGroupLogger;
use Poweradmin\Infrastructure\Logger\DbUserLogger;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Application\Service\Auth\ApiKeyAuthenticationMiddleware;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Application\Service\Auth\BasicAuthenticationMiddleware;
use Poweradmin\Infrastructure\Service\RedirectService;
use Poweradmin\Infrastructure\Service\ZoneSyncService;
use Poweradmin\Infrastructure\Session\SessionService;
use Psr\Log\LoggerInterface;

/**
 * The services controllers ask for, split by concern into five factories that
 * each memoize their per-request instances. The flat accessors below delegate
 * to them so call sites and test doubles keep one entry point.
 */
class ControllerServiceFactory implements ModuleServices
{
    private BackendServices $backend;
    private UserServices $users;
    private AuthServices $auth;
    private ZoneServices $zones;
    private RecordServices $records;

    public function __construct(PDO $db, ConfigurationInterface $config, LoggerInterface $logger)
    {
        $this->backend = new BackendServices($db, $config, $logger);
        $this->users = new UserServices($db, $config, $logger, $this);
        $this->auth = new AuthServices($db, $config, $logger, $this);
        $this->zones = new ZoneServices($db, $config, $logger, $this);
        $this->records = new RecordServices($db, $config, $logger, $this);
    }

    public function backend(): BackendServices
    {
        return $this->backend;
    }

    public function users(): UserServices
    {
        return $this->users;
    }

    public function auth(): AuthServices
    {
        return $this->auth;
    }

    public function zones(): ZoneServices
    {
        return $this->zones;
    }

    public function records(): RecordServices
    {
        return $this->records;
    }

    public function dnsBackendProvider(): DnsBackendProviderInterface
    {
        return $this->backend->dnsBackendProvider();
    }

    public function apiClient(): ?PowerdnsApiClient
    {
        return $this->backend->apiClient();
    }

    public function repositoryFactory(?DnsBackendProviderInterface $backendProvider = null): RepositoryFactory
    {
        return $this->backend->repositoryFactory($backendProvider);
    }

    public function zoneRepository(): ZoneRepositoryInterface
    {
        return $this->backend->zoneRepository();
    }

    public function domainRepository(): DomainRepositoryInterface
    {
        return $this->backend->domainRepository();
    }

    public function recordRepository(): RecordRepositoryInterface
    {
        return $this->backend->recordRepository();
    }

    public function soaRecordManager(): SOARecordManagerInterface
    {
        return $this->backend->soaRecordManager();
    }

    public function dnssecProvider(): DnssecProviderInterface
    {
        return $this->backend->dnssecProvider();
    }

    public function dnsDataService(): DnsDataService
    {
        return $this->backend->dnsDataService();
    }

    public function zoneMetadataStore(): ZoneMetadataStoreInterface
    {
        return $this->backend->zoneMetadataStore();
    }

    public function consistencyChecker(): ConsistencyCheckerInterface
    {
        return $this->backend->consistencyChecker();
    }

    public function powerdnsStatusService(): PowerdnsStatusService
    {
        return $this->backend->powerdnsStatusService();
    }

    public function zoneSyncService(): ZoneSyncService
    {
        return $this->backend->zoneSyncService();
    }

    public function userRepository(): UserRepositoryInterface
    {
        return $this->users->userRepository();
    }

    public function permissionService(): PermissionService
    {
        return $this->users->permissionService();
    }

    public function apiPermissionService(): ApiPermissionService
    {
        return $this->users->apiPermissionService();
    }

    public function userManagementService(): UserManagementService
    {
        return $this->users->userManagementService();
    }

    public function userGroupRepository(): UserGroupRepositoryInterface
    {
        return $this->users->userGroupRepository();
    }

    public function userGroupMemberRepository(): UserGroupMemberRepositoryInterface
    {
        return $this->users->userGroupMemberRepository();
    }

    public function permissionTemplateRepository(): PermissionTemplateRepositoryInterface
    {
        return $this->users->permissionTemplateRepository();
    }

    public function permissionTemplateWriteService(): PermissionTemplateWriteService
    {
        return $this->users->permissionTemplateWriteService();
    }

    public function userPreferenceService(): UserPreferenceService
    {
        return $this->users->userPreferenceService();
    }

    public function userTimezoneService(): UserTimezoneService
    {
        return $this->users->userTimezoneService();
    }

    public function paginationService(): PaginationService
    {
        return $this->users->paginationService();
    }

    public function userProvisioningService(): UserProvisioningService
    {
        return $this->users->userProvisioningService();
    }

    public function userAgreementRepository(): UserAgreementRepositoryInterface
    {
        return $this->users->userAgreementRepository();
    }

    public function userLogger(): DbUserLogger
    {
        return $this->users->userLogger();
    }

    public function groupLogger(): DbGroupLogger
    {
        return $this->users->groupLogger();
    }

    public function passwordPolicyService(): PasswordPolicyService
    {
        return $this->users->passwordPolicyService();
    }

    public function passwordGenerationService(): PasswordGenerationService
    {
        return $this->users->passwordGenerationService();
    }

    public function sessionService(): SessionService
    {
        return $this->auth->sessionService();
    }

    public function redirectService(): RedirectService
    {
        return $this->auth->redirectService();
    }

    public function authenticationService(): AuthenticationService
    {
        return $this->auth->authenticationService();
    }

    public function clientContext(): ClientContext
    {
        return $this->auth->clientContext();
    }

    public function urlService(): UrlService
    {
        return $this->auth->urlService();
    }

    public function userMfaRepository(): UserMfaRepositoryInterface
    {
        return $this->auth->userMfaRepository();
    }

    public function mfaService(): MfaService
    {
        return $this->auth->mfaService();
    }

    public function apiKeyRepository(): ApiKeyRepositoryInterface
    {
        return $this->auth->apiKeyRepository();
    }

    public function apiKeyAuthenticationMiddleware(): ApiKeyAuthenticationMiddleware
    {
        return $this->auth->apiKeyAuthenticationMiddleware();
    }

    public function basicAuthenticationMiddleware(): BasicAuthenticationMiddleware
    {
        return $this->auth->basicAuthenticationMiddleware();
    }

    public function passwordResetTokenRepository(): PasswordResetTokenRepositoryInterface
    {
        return $this->auth->passwordResetTokenRepository();
    }

    public function usernameRecoveryRepository(): UsernameRecoveryRepositoryInterface
    {
        return $this->auth->usernameRecoveryRepository();
    }

    public function auditService(): AuditService
    {
        return $this->auth->auditService();
    }

    public function auditLogWriter(): AuditLogWriter
    {
        return $this->auth->auditLogWriter();
    }

    public function apiLogger(): DbApiLogger
    {
        return $this->auth->apiLogger();
    }

    public function mailService(): MailService
    {
        return $this->auth->mailService();
    }

    public function samlConfigurationService(): SamlConfigurationService
    {
        return $this->auth->samlConfigurationService();
    }

    public function oidcConfigurationService(): OidcConfigurationService
    {
        return $this->auth->oidcConfigurationService();
    }

    public function recaptchaService(): RecaptchaService
    {
        return $this->auth->recaptchaService();
    }

    public function loginAttemptService(): LoginAttemptService
    {
        return $this->auth->loginAttemptService();
    }

    public function zoneManagementService(PdnsCapabilities|Closure|null $capabilities = null): ZoneManagementService
    {
        return $this->zones->zoneManagementService($capabilities);
    }

    public function zoneCreateService(PdnsCapabilities|Closure|null $capabilities = null): ZoneCreateService
    {
        return $this->zones->zoneCreateService($capabilities);
    }

    public function zoneSigningService(): ZoneSigningService
    {
        return $this->zones->zoneSigningService();
    }

    public function zoneOwnershipModeService(): ZoneOwnershipModeService
    {
        return $this->zones->zoneOwnershipModeService();
    }

    public function zoneCreateOwnershipResolver(): ZoneCreateOwnershipResolver
    {
        return $this->zones->zoneCreateOwnershipResolver();
    }

    public function zoneOwnershipFormResolver(): ZoneOwnershipFormResolver
    {
        return $this->zones->zoneOwnershipFormResolver();
    }

    public function zoneOwnershipGuard(): ZoneOwnershipGuard
    {
        return $this->zones->zoneOwnershipGuard();
    }

    public function zoneGroupService(): ZoneGroupService
    {
        return $this->zones->zoneGroupService();
    }

    public function zoneListPermissionService(): ZoneListPermissionService
    {
        return $this->zones->zoneListPermissionService();
    }

    public function zoneGroupRepository(): ZoneGroupRepositoryInterface
    {
        return $this->zones->zoneGroupRepository();
    }

    public function zoneSortingService(?UserContextService $userContext = null): ZoneSortingService
    {
        return $this->zones->zoneSortingService($userContext);
    }

    public function zoneMetadataService(): ZoneMetadataService
    {
        return $this->zones->zoneMetadataService();
    }

    public function dashboardStatsService(): DashboardStatsService
    {
        return $this->zones->dashboardStatsService();
    }

    public function domainManager(): DomainManagerInterface
    {
        return $this->zones->domainManager();
    }

    public function zoneTemplateApplier(): ZoneTemplateApplier
    {
        return $this->zones->zoneTemplateApplier();
    }

    public function templateRecordLinkRepository(): TemplateRecordLinkRepositoryInterface
    {
        return $this->zones->templateRecordLinkRepository();
    }

    public function supermasterManager(): SupermasterManager
    {
        return $this->zones->supermasterManager();
    }

    public function zoneTemplateRepository(): ZoneTemplateRepositoryInterface
    {
        return $this->zones->zoneTemplateRepository();
    }

    public function zoneTemplateSync(): ZoneTemplateSyncRepositoryInterface
    {
        return $this->zones->zoneTemplateSync();
    }

    public function zoneTemplateService(): ZoneTemplateService
    {
        return $this->zones->zoneTemplateService();
    }

    public function zoneTemplateAccessPolicy(): ZoneTemplateAccessPolicy
    {
        return $this->zones->zoneTemplateAccessPolicy();
    }

    public function zoneTemplateWriteService(): ZoneTemplateWriteService
    {
        return $this->zones->zoneTemplateWriteService();
    }

    public function zoneTemplateRecordService(): ZoneTemplateRecordService
    {
        return $this->zones->zoneTemplateRecordService();
    }

    public function catalogZoneService(): CatalogZoneService
    {
        return $this->zones->catalogZoneService();
    }

    public function zoneLogger(): DbZoneLogger
    {
        return $this->zones->zoneLogger();
    }

    public function recordManager(): RecordManagerInterface
    {
        return $this->records->recordManager();
    }

    public function recordChangeLogger(): RecordChangeWriterInterface
    {
        return $this->records->recordChangeLogger();
    }

    public function recordChangeLog(): RecordChangeLogger
    {
        return $this->records->recordChangeLog();
    }

    public function rrsetReplaceService(): RRSetReplaceService
    {
        return $this->records->rrsetReplaceService();
    }

    public function recordCommentService(): RecordCommentService
    {
        return $this->records->recordCommentService();
    }

    public function zoneEditService(): ZoneEditService
    {
        return $this->records->zoneEditService();
    }

    public function zoneChangeRequestRepository(): ZoneChangeRequestRepositoryInterface
    {
        return $this->records->zoneChangeRequestRepository();
    }

    public function zoneChangeRequestService(): ZoneChangeRequestService
    {
        return $this->records->zoneChangeRequestService();
    }

    public function changeRequestNotificationService(): ChangeRequestNotificationService
    {
        return $this->records->changeRequestNotificationService();
    }

    public function recordManagerService(): RecordManagerService
    {
        return $this->records->recordManagerService();
    }

    public function recordAddService(): RecordAddService
    {
        return $this->records->recordAddService();
    }

    public function recordEditService(): RecordEditService
    {
        return $this->records->recordEditService();
    }

    public function changeApprovalContext(): ChangeApprovalContext
    {
        return $this->records->changeApprovalContext();
    }

    public function reverseRecordCreator(): ReverseRecordCreator
    {
        return $this->records->reverseRecordCreator();
    }

    public function recordDeletionService(): RecordDeletionService
    {
        return $this->records->recordDeletionService();
    }

    public function batchReverseRecordCreator(): BatchReverseRecordCreator
    {
        return $this->records->batchReverseRecordCreator();
    }

    public function reverseTtlResolver(): ReverseTtlResolver
    {
        return $this->records->reverseTtlResolver();
    }

    public function recordTypeDefaultRepository(): RecordTypeDefaultRepositoryInterface
    {
        return $this->records->recordTypeDefaultRepository();
    }

    public function dnsValidatorRegistry(): DnsValidatorRegistry
    {
        return $this->records->dnsValidatorRegistry();
    }

    public function emailTemplateService(): EmailTemplateService
    {
        return $this->records->emailTemplateService();
    }
}
