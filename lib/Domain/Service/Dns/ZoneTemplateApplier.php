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

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\ZoneTemplateSyncRepositoryInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\TemplateRecordLinkRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Service\DnsValidation\NIDRecordValidator;
use Poweradmin\Domain\Service\Template\ZoneTemplatePlaceholders;
use Poweradmin\Domain\Utility\DnsHelper;
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

    private TransactionInterface $transaction;
    private DnsBackendProviderInterface $backendProvider;
    private SOARecordManagerInterface $soaRecordManager;
    private DomainRepositoryInterface $domainRepository;
    private ZoneTemplateRepositoryInterface $zoneTemplateRepository;
    private TemplateRecordLinkRepositoryInterface $recordLinks;
    private ZoneTemplateSyncRepositoryInterface $syncService;
    private ZoneTemplatePlaceholders $placeholders;
    private RecordChangeWriterInterface $changeLogger;
    private LoggerInterface $logger;

    public function __construct(
        TransactionInterface $transaction,
        DnsBackendProviderInterface $backendProvider,
        SOARecordManagerInterface $soaRecordManager,
        DomainRepositoryInterface $domainRepository,
        ZoneTemplateRepositoryInterface $zoneTemplateRepository,
        TemplateRecordLinkRepositoryInterface $recordLinks,
        ZoneTemplateSyncRepositoryInterface $syncService,
        ZoneTemplatePlaceholders $placeholders,
        RecordChangeWriterInterface $changeLogger,
        LoggerInterface $logger
    ) {
        $this->transaction = $transaction;
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
     * zone repointed, but no template records are written. Template records that
     * would break the CNAME rule are skipped and reported; the rest are kept.
     */
    public function applyTemplate(int $zoneId, int $templateId, int $defaultTtl, bool $writeRecords): ZoneWriteResult
    {
        // On the API backend it opens only after the last PowerDNS call: on SQLite an open
        // transaction holds the write lock, so PowerDNS sharing the file would wait on it.
        $joinsBackend = $this->backendProvider->supportsLocalWriteTransaction();
        if ($joinsBackend) {
            $this->transaction->begin();
        }
        try {
            $refused = [];
            $conflicts = [];
            if ($templateId !== 0) {
                $refused = $this->removeTemplateRecords($zoneId, $templateId);
                if ($writeRecords) {
                    [$writeRefused, $conflicts] = $this->writeTemplateRecords($zoneId, $templateId, $defaultTtl);
                    $refused = array_merge($refused, $writeRefused);
                }
            }

            if (!$joinsBackend) {
                $this->transaction->begin();
            }
            $this->relinkZoneTemplate($zoneId, $templateId, $refused === [] && $conflicts === []);
            $this->finishTransaction(true);

            $conflictMessage = $conflicts === [] ? '' : sprintf(_('Template records skipped because they conflict with a CNAME at the same name: %s'), implode(', ', $conflicts));
            if ($refused !== []) {
                $message = sprintf(_('Failed to update zone records: %s'), implode(', ', $refused));
                return ZoneWriteResult::backendFailure(trim($message . ' ' . $conflictMessage));
            }
            if ($conflictMessage !== '') {
                return ZoneWriteResult::failure($conflictMessage);
            }

            return ZoneWriteResult::ok($zoneId);
        } catch (Throwable $e) {
            $this->finishTransaction(false);
            // The message can name paths, SQL or classes, so it goes to the log and the user gets generic text
            $this->logger->error('Applying template {template} to zone {zone} failed: {error}', ['template' => $templateId, 'zone' => $zoneId, 'error' => $e->getMessage()]);
            return ZoneWriteResult::backendFailure(_('An error occurred. Please try again.'));
        }
    }

    /**
     * Template CNAMEs a new zone could not hold: one that shares its name with
     * another template entry, or one at the zone apex.
     *
     * @return list<string> "name CNAME" for each
     */
    public function conflictingTemplateCnames(int $templateId, string $domain): array
    {
        $entriesByName = $this->templateEntriesByName($this->zoneTemplateRepository->getZoneTemplateRecords($templateId), $domain);

        $conflicts = [];
        foreach ($entriesByName as $name => $entries) {
            $cnames = array_filter(array_keys($entries), fn(string $entry): bool => str_starts_with($entry, RecordType::CNAME . ' '));
            if ($cnames !== [] && (count($entries) > 1 || DnsHelper::isZoneApex((string) $name, $domain))) {
                $conflicts[] = $name . ' ' . RecordType::CNAME;
            }
        }
        return $conflicts;
    }

    /**
     * Commit or roll back whatever is still open; nothing is when the failure came before the local writes.
     */
    private function finishTransaction(bool $commit): void
    {
        if (!$this->transaction->inTransaction()) {
            return;
        }
        if ($commit) {
            $this->transaction->commit();
        } else {
            $this->transaction->rollBack();
        }
    }

    /**
     * Remove the records an earlier application of this template wrote.
     *
     * @return list<string> API backend records PowerDNS refused to delete
     */
    private function removeTemplateRecords(int $zoneId, int $templateId): array
    {
        if ($this->backendProvider->recordIdsAreNumeric()) {
            $this->logDeletes($this->recordLinks->removeLinkedRecords($zoneId, $templateId), $zoneId);
            return [];
        }

        // Pre-existing API zones from before records_zone_templ_api have no links,
        // so their template records are left in place rather than matched fuzzily.
        $links = $this->recordLinks->listEncodedLinks($zoneId, $templateId);
        if ($links === []) {
            return [];
        }

        // Records removed out-of-band are unlinked but not deleted or logged.
        $recordsByEncodedId = [];
        foreach ($this->backendProvider->getRecordsByZoneId($zoneId) as $record) {
            if (isset($record['id'])) {
                $recordsByEncodedId[(string) $record['id']] = $record;
            }
        }

        $removed = [];
        $refused = [];
        $unlink = [];
        foreach ($links as $link) {
            $record = $recordsByEncodedId[(string) $link['record_id']] ?? null;
            if ($record === null) {
                $unlink[] = (int) $link['id'];
                continue;
            }
            if (!$this->backendProvider->deleteRecord((string) $link['record_id'])) {
                $refused[] = (string) $link['record_id'];
                continue;
            }
            $unlink[] = (int) $link['id'];
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
        $this->logDeletes($removed, $zoneId);

        $this->recordLinks->removeEncodedLinks($unlink);

        return $refused;
    }

    /**
     * Write the template's records with placeholders resolved and link each one
     * back to the template. Records the zone already holds are skipped, and so
     * are records that would sit beside a stored CNAME and template CNAMEs that
     * would share their name with any other record. The apex SOA and NS are always written.
     *
     * @return array{0: list<string>, 1: list<string>} API backend records PowerDNS
     *     refused to add, and records skipped for a CNAME conflict
     */
    private function writeTemplateRecords(int $zoneId, int $templateId, int $defaultTtl): array
    {
        $refused = [];
        $conflicts = [];
        $domain = (string) $this->domainRepository->getDomainNameById($zoneId);
        $soaRecord = $this->soaRecordManager->getSOARecord($zoneId);
        $templateRecords = $this->zoneTemplateRepository->getZoneTemplateRecords($templateId);

        $typesByName = $templateRecords === [] ? [] : $this->recordTypesByName($zoneId);
        $templateEntriesByName = $this->templateEntriesByName($templateRecords, $domain);

        foreach ($templateRecords as $record) {
            $type = (string) $record['type'];
            if (!self::shouldApplyTemplateRecord($domain, $type)) {
                continue;
            }
            $name = $this->resolveName($record, $domain);

            if ($type === 'SOA') {
                if ($this->backendProvider->managesSoaRecord()) {
                    // PowerDNS manages the SOA of API-created zones
                    continue;
                }
                $content = $this->replaceSoa($zoneId, $soaRecord, (string) $record['content'], $domain);
            } else {
                $content = $this->placeholders->parseTemplateValue((string) $record['content'], $domain, $type);
            }

            $ttl = (int) ($record['ttl'] ?: $defaultTtl);
            $prio = intval($record['prio']);
            // A template saved before NID kept its preference in the content still holds a bare node id
            if (strtoupper($type) === RecordType::NID) {
                $content = NIDRecordValidator::contentWithPreference($content, $prio);
                $prio = 0;
            }

            if ($this->backendProvider->recordExists($zoneId, $name, $type, $content)) {
                continue;
            }

            // The apex SOA and NS stay even beside a stored apex CNAME, so the zone keeps serving
            $apexAnchor = $type === 'SOA' || ($type === 'NS' && DnsHelper::isZoneApex($name, $domain));
            if (!$apexAnchor && self::breaksCnameRule($type, $typesByName[$name] ?? [], count($templateEntriesByName[$name] ?? []))) {
                $conflicts[] = $name . ' ' . $type;
                continue;
            }

            $recordId = $this->backendProvider->addRecordGetId($zoneId, $name, $type, $content, $ttl, $prio);
            if ($recordId === null) {
                // A refused add only fails the apply on the API backend; SQL keeps skipping it
                if (!$this->backendProvider->recordIdsAreNumeric()) {
                    $refused[] = $name . ' ' . $type;
                }
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

        return [$refused, $conflicts];
    }

    /**
     * The record types the zone holds, keyed by lowercase owner name.
     *
     * @return array<string, list<string>>
     */
    private function recordTypesByName(int $zoneId): array
    {
        $typesByName = [];
        foreach ($this->backendProvider->getRecordsByZoneId($zoneId) as $record) {
            $name = strtolower(rtrim((string) ($record['name'] ?? ''), '.'));
            $typesByName[$name][] = strtoupper((string) ($record['type'] ?? ''));
        }
        return $typesByName;
    }

    /**
     * The distinct type and content pairs the template writes at each name.
     *
     * @param array<int, array<string, mixed>> $templateRecords
     * @return array<string, array<string, true>>
     */
    private function templateEntriesByName(array $templateRecords, string $domain): array
    {
        $entries = [];
        foreach ($templateRecords as $record) {
            $type = (string) $record['type'];
            if (!self::shouldApplyTemplateRecord($domain, $type)) {
                continue;
            }
            $content = $type === 'SOA' ? '' : $this->placeholders->parseTemplateValue((string) $record['content'], $domain, $type);
            $entries[$this->resolveName($record, $domain)][strtoupper($type) . ' ' . $content] = true;
        }
        return $entries;
    }

    /**
     * A template name without [ZONE] is relative to the zone, like a form entry.
     *
     * @param array<string, mixed> $record
     */
    private function resolveName(array $record, string $domain): string
    {
        return strtolower(DnsHelper::restoreZoneSuffix($this->placeholders->parseTemplateValue((string) $record['name'], $domain), $domain));
    }

    /**
     * A CNAME owns its name alone. A template CNAME yields to any stored record or
     * other template entry at its name, so the outcome never depends on row order.
     *
     * @param list<string> $storedTypes
     */
    private static function breaksCnameRule(string $type, array $storedTypes, int $templateEntriesAtName): bool
    {
        if (strtoupper($type) === RecordType::CNAME) {
            return $storedTypes !== [] || $templateEntriesAtName > 1;
        }
        return in_array(RecordType::CNAME, $storedTypes, true);
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
    private function relinkZoneTemplate(int $zoneId, int $templateId, bool $inSync = true): void
    {
        $this->zoneTemplateRepository->assignTemplateToZone($zoneId, $templateId);

        foreach ($this->zoneTemplateRepository->listZoneRowIds($zoneId) as $zonesId) {
            $this->syncService->removeStaleSyncRecords($zonesId, $templateId);
            if ($templateId !== 0) {
                $this->syncService->createSyncRecord($zonesId, $templateId);
                if ($inSync) {
                    $this->syncService->markZoneAsSynced($zonesId, $templateId);
                }
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
