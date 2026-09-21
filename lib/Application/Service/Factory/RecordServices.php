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
use Poweradmin\Application\Service\ChangeApprovalContext;
use Poweradmin\Application\Service\ChangeRequestNotificationService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Application\Service\EmailTemplateService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\RecordAddService;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Domain\Repository\RecordTypeDefaultRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\BatchReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\RRSetReplaceService;
use Poweradmin\Domain\Service\Dns\RecordDeletionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\DomainRecordCreator;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Domain\Service\ZoneChangeRequestService;
use Poweradmin\Domain\Service\ZoneEditService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Infrastructure\Repository\DbRecordTypeDefaultRepository;
use Poweradmin\Infrastructure\Repository\DbZoneChangeRequestRepository;
use Poweradmin\Application\Service\DnsServiceFactory;
use Poweradmin\Domain\Service\Dns\BindZoneFileGenerator;
use Psr\Log\LoggerInterface;

/**
 * Record writes and the change-approval flow around them: managers, reverse
 * record creation, comments, and the change request pipeline.
 */
class RecordServices
{
    private PDO $db;
    private ConfigurationManager $config;
    private LoggerInterface $logger;
    private ControllerServiceFactory $services;

    private ?RecordChangeLogger $recordChangeLogger = null;
    private ?RecordManagerInterface $recordManager = null;
    private ?RecordCommentService $recordCommentService = null;
    private ?ZoneChangeRequestRepositoryInterface $zoneChangeRequestRepository = null;
    private ?ZoneChangeRequestService $zoneChangeRequestService = null;
    private ?ChangeRequestNotificationService $changeRequestNotificationService = null;
    private ?RecordTypeDefaultRepositoryInterface $recordTypeDefaultRepository = null;

    public function __construct(PDO $db, ConfigurationManager $config, LoggerInterface $logger, ControllerServiceFactory $services)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->services = $services;
    }

    public function recordManager(): RecordManagerInterface
    {
        return $this->recordManager ??= DnsServiceFactory::createRecordManager($this->db, $this->config, $this->services->dnsBackendProvider(), $this->services->permissionService());
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
        return $this->recordChangeLogger ??= new RecordChangeLogger($this->db);
    }

    public function rrsetReplaceService(): RRSetReplaceService
    {
        return new RRSetReplaceService(
            $this->db,
            $this->config,
            $this->services->dnsBackendProvider(),
            DnsServiceFactory::createDnsRecordValidationService($this->db, $this->config, $this->services->dnsBackendProvider()),
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
            $this->services->auditService()
        );
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
            DnsServiceFactory::createDnsRecordValidationService($this->db, $this->config, $this->services->dnsBackendProvider()),
            $this->services->recordRepository(),
            $this->services->domainRepository(),
            $this->services->zoneRepository(),
            $this->recordManager(),
            $this->services->soaRecordManager(),
            $this->services->zoneManagementService(),
            $this->services->dnsBackendProvider(),
            $this->db,
            $this->config,
            $this->services->repositoryFactory()->createRecordCommentRepository(),
            RecordChangeLogger::withChangeset(...),
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
        $records = $this->services->recordRepository()->getRecordsFromDomainId((string)$this->config->get('database', 'type', 'mysql'), $zoneId);
        if ($records === []) {
            return null;
        }

        return (new BindZoneFileGenerator())->generate($zoneName, $records);
    }

    public function changeRequestNotificationService(): ChangeRequestNotificationService
    {
        return $this->changeRequestNotificationService ??= new ChangeRequestNotificationService(
            $this->db,
            $this->config,
            new MailService($this->config, $this->logger),
            new EmailTemplateService($this->config),
            $this->services->domainRepository(),
            $this->services->permissionService(),
            $this->logger,
            $this->services->auditService(),
            $this->services->soaRecordManager()
        );
    }

    public function recordManagerService(): RecordManagerService
    {
        return new RecordManagerService(
            $this->db,
            $this->services->domainRepository(),
            $this->recordManager(),
            $this->recordCommentService(),
            $this->services->auditService(),
            $this->config,
            $this->services->dnsBackendProvider()
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
            $this->config,
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
            $this->config
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
            fn() => DnssecProviderFactory::create($this->db, $this->config)
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
