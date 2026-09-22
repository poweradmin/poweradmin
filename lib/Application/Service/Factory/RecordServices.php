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
use Poweradmin\Application\Service\Zone\ChangeApprovalContext;
use Poweradmin\Application\Service\Zone\ChangeRequestNotificationService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\Mail\EmailTemplateService;
use Poweradmin\Application\Service\Record\RecordAddService;
use Poweradmin\Application\Service\Record\RecordCommentService;
use Poweradmin\Application\Service\Record\RecordCommentSyncService;
use Poweradmin\Application\Service\Record\RecordEditService;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Domain\Repository\RecordTypeDefaultRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\BatchReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\RRSetReplaceService;
use Poweradmin\Domain\Service\Dns\RecordDeletionService;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationService;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Domain\Service\Dns\DomainRecordCreator;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestService;
use Poweradmin\Domain\Service\Zone\ZoneEditService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Infrastructure\Repository\DbRecordTypeDefaultRepository;
use Poweradmin\Infrastructure\Repository\DbZoneChangeRequestRepository;
use Poweradmin\Domain\Service\Dns\BindZoneFileGenerator;
use Psr\Log\LoggerInterface;

/**
 * Record writes and the change-approval flow around them: managers, reverse
 * record creation, comments, and the change request pipeline.
 */
final class RecordServices
{
    private PDO $db;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private ControllerServiceFactory $services;

    private ?RecordChangeLogger $recordChangeLogger = null;
    private ?RecordManagerInterface $recordManager = null;
    private ?DnsRecordValidationServiceInterface $dnsRecordValidationService = null;
    private ?RecordCommentService $recordCommentService = null;
    private ?ZoneChangeRequestRepositoryInterface $zoneChangeRequestRepository = null;
    private ?ZoneChangeRequestService $zoneChangeRequestService = null;
    private ?ChangeRequestNotificationService $changeRequestNotificationService = null;
    private ?RecordTypeDefaultRepositoryInterface $recordTypeDefaultRepository = null;
    private ?DnsValidatorRegistry $dnsValidatorRegistry = null;
    private ?EmailTemplateService $emailTemplateService = null;

    public function __construct(PDO $db, ConfigurationInterface $config, LoggerInterface $logger, ControllerServiceFactory $services)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->services = $services;
    }

    public function recordManager(): RecordManagerInterface
    {
        return $this->recordManager ??= new RecordManager(
            $this->services->transaction(),
            $this->config,
            $this->dnsRecordValidationService(),
            $this->services->soaRecordManager(),
            $this->services->domainRepository(),
            $this->services->repositoryFactory(),
            fn() => $this->services->dnssecProvider(),
            $this->services->dnsBackendProvider(),
            $this->services->permissionService(),
            $this->recordChangeLog(),
            $this->services->templateRecordLinkRepository(),
            $this->services->actor()
        );
    }

    public function dnsRecordValidationService(): DnsRecordValidationServiceInterface
    {
        return $this->dnsRecordValidationService ??= new DnsRecordValidationService(
            $this->dnsValidatorRegistry(),
            new DnsCommonValidator($this->services->dnsBackendProvider()),
            $this->services->domainRepository(),
            new DNSViolationValidator($this->services->recordRepository())
        );
    }

    /**
     * Built once per request: the registry instantiates every record-type validator.
     */
    public function dnsValidatorRegistry(): DnsValidatorRegistry
    {
        return $this->dnsValidatorRegistry ??= new DnsValidatorRegistry($this->config, $this->services->dnsBackendProvider());
    }

    public function emailTemplateService(): EmailTemplateService
    {
        return $this->emailTemplateService ??= new EmailTemplateService($this->config);
    }

    public function recordChangeLogger(): RecordChangeWriterInterface
    {
        return $this->recordChangeLog();
    }

    /**
     * The same change log as recordChangeLogger(), for the log views that read
     * it back rather than write to it.
     */
    public function recordChangeLog(): RecordChangeLogger
    {
        return $this->recordChangeLogger ??= new RecordChangeLogger($this->db, $this->config, $this->services->actor());
    }

    public function rrsetReplaceService(): RRSetReplaceService
    {
        return new RRSetReplaceService(
            $this->services->transaction(),
            $this->config,
            $this->services->dnsBackendProvider(),
            $this->dnsRecordValidationService(),
            $this->services->recordRepository(),
            $this->recordManager(),
            $this->services->soaRecordManager(),
            $this->services->auditService()
        );
    }

    public function recordCommentService(): RecordCommentService
    {
        return $this->recordCommentService ??= new RecordCommentService(
            $this->services->repositoryFactory()->createRecordCommentRepository(),
            $this->services->repositoryFactory()->createRecordLinkedCommentRepository()
        );
    }

    public function zoneEditService(): ZoneEditService
    {
        $comments = $this->recordCommentService();

        return new ZoneEditService(
            $this->config,
            $this->services->permissionService(),
            $this->services->zoneRepository(),
            $this->services->domainRepository(),
            $this->services->recordRepository(),
            $this->recordManager(),
            $this->services->soaRecordManager(),
            $comments,
            new RecordCommentSyncService($comments, $this->services->recordRepository(), $this->services->dnsBackendProvider()),
            $this->services->auditService(),
            $this->recordCommentsEnabled(),
            $this->zoneCommentsEnabled()
        );
    }

    /**
     * The interface.* switches double as persistence policy: a hidden comment
     * column is not written, and PTR handling follows the add-reverse-record toggle.
     */
    private function recordCommentsEnabled(): bool
    {
        return (bool)$this->config->get('interface', 'show_record_comments', false);
    }

    private function zoneCommentsEnabled(): bool
    {
        return (bool)$this->config->get('interface', 'show_zone_comments', true);
    }

    private function reverseHandling(): bool
    {
        return (bool)$this->config->get('interface', 'add_reverse_record', false);
    }

    public function zoneChangeRequestRepository(): ZoneChangeRequestRepositoryInterface
    {
        return $this->zoneChangeRequestRepository ??= new DbZoneChangeRequestRepository($this->db);
    }

    public function zoneChangeRequestService(): ZoneChangeRequestService
    {
        return $this->zoneChangeRequestService ??= new ZoneChangeRequestService(
            $this->zoneChangeRequestRepository(),
            $this->zoneEditService(),
            $this->dnsRecordValidationService(),
            $this->services->recordRepository(),
            $this->services->domainRepository(),
            $this->services->zoneRepository(),
            $this->recordManager(),
            $this->services->soaRecordManager(),
            $this->services->zoneManagementService(),
            $this->services->dnsBackendProvider(),
            $this->services->transaction(),
            $this->config,
            $this->recordCommentsEnabled(),
            $this->zoneCommentsEnabled(),
            $this->services->repositoryFactory()->createRecordCommentRepository(),
            $this->recordChangeLog()->withChangeset(...),
            $this->services->permissionService(),
            $this->changeRequestNotificationService(),
            $this->zoneFileSnapshot(...),
            $this->services->repositoryFactory()->createRecordLinkedCommentRepository()
        );
    }

    /**
     * The zone as a BIND zone file, or null when it has no records.
     */
    private function zoneFileSnapshot(int $zoneId, string $zoneName): ?string
    {
        $records = $this->services->recordRepository()->getRecordsFromDomainId($zoneId);
        if ($records === []) {
            return null;
        }

        return (new BindZoneFileGenerator())->generate($zoneName, $records);
    }

    public function changeRequestNotificationService(): ChangeRequestNotificationService
    {
        return $this->changeRequestNotificationService ??= new ChangeRequestNotificationService(
            $this->services->userRepository(),
            $this->config,
            $this->services->mailService(),
            $this->emailTemplateService(),
            $this->services->domainRepository(),
            $this->services->permissionService(),
            $this->services->urlService(),
            $this->logger,
            $this->services->auditService(),
            $this->services->soaRecordManager()
        );
    }

    public function recordManagerService(): RecordManagerService
    {
        return new RecordManagerService(
            $this->services->domainRepository(),
            $this->services->recordRepository(),
            $this->recordManager(),
            $this->recordCommentService(),
            $this->services->auditService(),
            $this->config
        );
    }

    public function recordAddService(): RecordAddService
    {
        $ttlResolver = $this->reverseTtlResolver();

        return new RecordAddService(
            $this->recordManagerService(),
            $this->reverseRecordCreator(),
            new DomainRecordCreator($this->config, $this->services->domainRepository(), $this->recordManager(), null, $ttlResolver),
            $ttlResolver,
            $this->services->permissionService(),
            $this->services->domainRepository(),
            $this->changeApprovalContext()
        );
    }

    public function recordEditService(): RecordEditService
    {
        $comments = $this->recordCommentService();

        return new RecordEditService(
            $this->recordManager(),
            $this->services->recordRepository(),
            $this->services->domainRepository(),
            $this->services->soaRecordManager(),
            $this->reverseRecordCreator(),
            $comments,
            new RecordCommentSyncService($comments, $this->services->recordRepository(), $this->services->dnsBackendProvider()),
            $this->services->auditService(),
            $this->config,
            $this->logger
        );
    }

    /**
     * Change-approval answers for one user and zone; built lazily so a request
     * that never asks does not open the repositories behind it.
     */
    public function changeApprovalContext(): ChangeApprovalContext
    {
        return new ChangeApprovalContext(
            $this->config,
            fn(): PermissionService => $this->services->permissionService(),
            fn(): ZoneRepositoryInterface => $this->services->zoneRepository(),
            fn(): ZoneChangeRequestRepositoryInterface => $this->zoneChangeRequestRepository()
        );
    }

    public function reverseRecordCreator(): ReverseRecordCreator
    {
        return new ReverseRecordCreator(
            $this->reverseHandling(),
            $this->services->auditService(),
            $this->services->domainRepository(),
            $this->recordManager(),
            $this->services->dnsBackendProvider()
        );
    }

    public function recordDeletionService(): RecordDeletionService
    {
        return new RecordDeletionService(
            $this->services->recordRepository(),
            $this->recordManager(),
            $this->reverseRecordCreator(),
            $this->services->auditService(),
            $this->reverseHandling()
        );
    }

    public function batchReverseRecordCreator(): BatchReverseRecordCreator
    {
        return new BatchReverseRecordCreator(
            $this->config,
            $this->services->auditService(),
            $this->services->domainRepository(),
            $this->services->recordRepository(),
            $this->recordManager(),
            fn() => $this->services->dnssecProvider(),
            $this->reverseHandling()
        );
    }

    public function reverseTtlResolver(): ReverseTtlResolver
    {
        return new ReverseTtlResolver($this->config, $this->recordTypeDefaultRepository());
    }

    public function recordTypeDefaultRepository(): RecordTypeDefaultRepositoryInterface
    {
        return $this->recordTypeDefaultRepository ??= new DbRecordTypeDefaultRepository($this->db);
    }
}
