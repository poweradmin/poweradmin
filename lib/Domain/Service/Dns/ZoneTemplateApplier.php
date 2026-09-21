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

namespace Poweradmin\Domain\Service\Dns;

use PDO;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\TemplateRecordLinkRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\RecordChangeWriterInterface;
use Poweradmin\Domain\Service\ZoneTemplatePlaceholders;
use Poweradmin\Domain\Service\ZoneTemplateSyncService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies a zone template to an existing zone: removes the records an earlier
 * application of that template wrote, writes and links the template's records,
 * repoints the zone at the template and reconciles the sync state.
 */
class ZoneTemplateApplier
{
    /**
     * IPv4 reverse zones (in-addr.arpa) were limited to NS/SOA because early
     * templates auto-populated A records for webip/mailip. PTR, LUA, CNAME and
     * TXT are allowed so LUA-driven PTR generation and RFC 2317 delegations are
     * not silently dropped. IPv6 reverse zones carry no restriction.
     */
    private const IPV4_REVERSE_TEMPLATE_TYPES = ['NS', 'SOA', 'PTR', 'LUA', 'CNAME', 'TXT'];

    private PDO $db;
    private DnsBackendProviderInterface $backendProvider;
    private SOARecordManagerInterface $soaRecordManager;
    private DomainRepositoryInterface $domainRepository;
    private ZoneTemplateRepositoryInterface $zoneTemplateRepository;
    private TemplateRecordLinkRepositoryInterface $recordLinks;
    private ZoneTemplateSyncService $syncService;
    private ZoneTemplatePlaceholders $placeholders;
    private RecordChangeWriterInterface $changeLogger;
    private LoggerInterface $logger;

    public function __construct(
        PDO $db,
        DnsBackendProviderInterface $backendProvider,
        SOARecordManagerInterface $soaRecordManager,
        DomainRepositoryInterface $domainRepository,
        ZoneTemplateRepositoryInterface $zoneTemplateRepository,
        TemplateRecordLinkRepositoryInterface $recordLinks,
        ZoneTemplateSyncService $syncService,
        ZoneTemplatePlaceholders $placeholders,
        RecordChangeWriterInterface $changeLogger,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->backendProvider = $backendProvider;
        $this->soaRecordManager = $soaRecordManager;
        $this->domainRepository = $domainRepository;
        $this->zoneTemplateRepository = $zoneTemplateRepository;
        $this->recordLinks = $recordLinks;
        $this->syncService = $syncService;
        $this->placeholders = $placeholders;
        $this->changeLogger = $changeLogger;
        $this->logger = $logger;
    }

    /**
     * Whether a template record belongs in the target zone.
     */
    public static function shouldApplyTemplateRecord(string $domain, string $type): bool
    {
        if (stripos($domain, 'in-addr.arpa') === false) {
            return true;
        }
        return in_array($type, self::IPV4_REVERSE_TEMPLATE_TYPES, true);
    }

    /**
     * Apply a template to a zone, or unlink the zone with template id 0.
     *
     * The caller has already authorised the change. $writeRecords carries the
     * zone-add grant: without it the earlier records are still removed and the
     * zone repointed, but no template records are written.
     */
    public function applyTemplate(int $zoneId, int $templateId, int $defaultTtl, bool $writeRecords): ZoneWriteResult
    {
        $this->db->beginTransaction();
        try {
            if ($templateId !== 0) {
                $this->removeTemplateRecords($zoneId, $templateId);
                if ($writeRecords) {
                    $this->writeTemplateRecords($zoneId, $templateId, $defaultTtl);
                }
            }

            $this->relinkZoneTemplate($zoneId, $templateId);
            $this->finishTransaction(true);

            return ZoneWriteResult::ok($zoneId);
        } catch (\Exception $e) {
            $this->finishTransaction(false);
            return ZoneWriteResult::backendFailure(sprintf(_('Failed to update zone records: %s'), $e->getMessage()));
        }
    }

    /**
     * Commit or roll back whatever is still open; the API backend path has
     * already committed before its writes.
     */
    private function finishTransaction(bool $commit): void
    {
        if (!$this->db->inTransaction()) {
            return;
        }
        if ($commit) {
            $this->db->commit();
        } else {
            $this->db->rollBack();
        }
    }

    /**
     * Remove the records an earlier application of this template wrote.
     */
    private function removeTemplateRecords(int $zoneId, int $templateId): void
    {
        if ($this->backendProvider->recordIdsAreNumeric()) {
            $this->logDeletes($this->recordLinks->removeLinkedRecords($zoneId, $templateId), $zoneId);
            return;
        }

        // Pre-existing API zones from before records_zone_templ_api have no links,
        // so their template records are left in place rather than matched fuzzily.
        $links = $this->recordLinks->listEncodedLinks($zoneId, $templateId);
        if ($links === []) {
            return;
        }

        // Records removed out-of-band are unlinked but not deleted or logged.
        $recordsByEncodedId = [];
        foreach ($this->backendProvider->getRecordsByZoneId($zoneId) as $record) {
            if (isset($record['id'])) {
                $recordsByEncodedId[(string) $record['id']] = $record;
            }
        }

        $removed = [];
        foreach ($links as $link) {
            $record = $recordsByEncodedId[(string) $link['record_id']] ?? null;
            if ($record !== null && $this->backendProvider->deleteRecord((string) $link['record_id'])) {
                $removed[] = [
                    'id' => $record['id'] ?? null,
                    'name' => $record['name'] ?? null,
                    'type' => $record['type'] ?? null,
                    'content' => $record['content'] ?? null,
                    'ttl' => isset($record['ttl']) ? (int) $record['ttl'] : null,
                    'prio' => isset($record['prio']) ? (int) $record['prio'] : null,
                    'disabled' => $record['disabled'] ?? null,
                ];
            }
        }
        $this->logDeletes($removed, $zoneId);

        $this->recordLinks->removeEncodedLinks(array_map(static fn(array $link): int => (int) $link['id'], $links));
    }

    /**
     * Write the template's records with placeholders resolved and link each one
     * back to the template. Records the zone already holds are skipped.
     */
    private function writeTemplateRecords(int $zoneId, int $templateId, int $defaultTtl): void
    {
        $domain = (string) $this->domainRepository->getDomainNameById($zoneId);
        $soaRecord = $this->soaRecordManager->getSOARecord($zoneId);
        $templateRecords = $this->zoneTemplateRepository->getZoneTemplateRecords($templateId);

        // Backend writes outside this transaction would not see the rows above until it commits
        if (!$this->backendProvider->supportsLocalWriteTransaction()) {
            $this->db->commit();
        }

        foreach ($templateRecords as $record) {
            $type = (string) $record['type'];
            if (!self::shouldApplyTemplateRecord($domain, $type)) {
                continue;
            }

            if ($type === 'SOA') {
                if ($this->backendProvider->managesSoaRecord()) {
                    // PowerDNS manages the SOA of API-created zones
                    continue;
                }
                $content = $this->replaceSoa($zoneId, $soaRecord, (string) $record['content'], $domain);
            } else {
                $content = $this->placeholders->parseTemplateValue((string) $record['content'], $domain, $type);
            }

            $name = $this->placeholders->parseTemplateValue((string) $record['name'], $domain);
            $ttl = (int) ($record['ttl'] ?: $defaultTtl);
            $prio = intval($record['prio']);

            if ($this->backendProvider->recordExists($zoneId, $name, $type, $content)) {
                continue;
            }

            $recordId = $this->backendProvider->addRecordGetId($zoneId, $name, $type, $content, $ttl, $prio);
            if ($recordId === null) {
                continue;
            }

            $this->recordLinks->linkRecord($zoneId, $recordId, $templateId);
            $this->captureChange(function () use ($recordId, $name, $type, $content, $ttl, $prio, $zoneId): void {
                $this->changeLogger->logRecordCreate([
                    'id' => $recordId,
                    'name' => $name,
                    'type' => $type,
                    'content' => $content,
                    'ttl' => $ttl,
                    'prio' => $prio,
                ], $zoneId);
            });
        }
    }

    /**
     * Drop the zone's SOA records and return the content of their replacement:
     * the zone's current SOA with a bumped serial, or the template's SOA when
     * the zone had none.
     */
    private function replaceSoa(int $zoneId, string $soaRecord, string $templateContent, string $domain): string
    {
        $this->logDeletes($this->recordLinks->removeSoaRecords($zoneId), $zoneId);

        $content = $this->soaRecordManager->getUpdatedSOARecord($soaRecord);
        if ($content === '') {
            $content = $this->placeholders->parseTemplateValue($templateContent, $domain, 'SOA');
        }

        return $content;
    }

    /**
     * Point the zone at the template and reconcile zone_template_sync, so stale
     * rows for a previous template stop reporting the zone as out of sync. A zone
     * shared by several owners has one zones row per owner, so handle every one.
     */
    private function relinkZoneTemplate(int $zoneId, int $templateId): void
    {
        $this->zoneTemplateRepository->assignTemplateToZone($zoneId, $templateId);

        foreach ($this->zoneTemplateRepository->listZoneRowIds($zoneId) as $zonesId) {
            $this->syncService->removeStaleSyncRecords($zonesId, $templateId);
            if ($templateId !== 0) {
                $this->syncService->createSyncRecord($zonesId, $templateId);
                $this->syncService->markZoneAsSynced($zonesId, $templateId);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function logDeletes(array $records, int $zoneId): void
    {
        if ($records === []) {
            return;
        }
        $this->captureChange(function () use ($records, $zoneId): void {
            foreach ($records as $record) {
                $this->changeLogger->logRecordDelete($record, $zoneId);
            }
        });
    }

    private function captureChange(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->logger->warning('Failed to write zone change log: {error}', ['error' => $e->getMessage()]);
        }
    }
}
