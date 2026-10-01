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

use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\RecordListingInterface;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Throwable;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Replaces every record of one name and type with a new set: validate all, delete the old set, insert the new one.
 */
class RRSetReplaceService
{
    private HostnameValidator $hostnameValidator;

    public function __construct(
        private readonly TransactionInterface $transaction,
        private readonly ConfigurationInterface $config,
        private readonly BackendCapabilitiesInterface $backend,
        private readonly DnsRecordValidationServiceInterface $validationService,
        private readonly RecordListingInterface $recordRepository,
        private readonly RecordManagerInterface $recordManager,
        private readonly SOARecordManagerInterface $soaRecordManager,
        private readonly AuditLoggerInterface $audit
    ) {
        $this->hostnameValidator = new HostnameValidator(HostnamePolicy::fromConfig($config));
    }

    /**
     * Validation runs before the old set is touched so a refused record never costs the caller its RRSet.
     * The serial bump shares the transaction; the rectify runs after the commit.
     *
     * @param list<array{content: string, priority: int, disabled: int}> $records Parsed and content-formatted input records
     * @return array{success: bool, message: string, refusal?: Refusal, name?: string, records?: list<array{content: string, ttl: int, priority: int, disabled: int}>, write?: RecordWriteResult, content?: string}
     *   On failure 'write' carries the refused record write and 'content' the record it was for, so the caller can word the refusal.
     */
    public function replace(int $zoneId, string $zoneName, string $fqdn, string $type, int $ttl, array $records): array
    {
        $useTransaction = $this->backend->supportsLocalWriteTransaction();
        if ($useTransaction) {
            $this->transaction->begin();
        }

        try {
            $normalizedName = $this->hostnameValidator->normalizeRecordName($fqdn, $zoneName);

            $validation = $this->validateRecords($zoneId, $normalizedName, $type, $ttl, $records);
            if ($validation['error'] !== null) {
                return $this->rollBack($useTransaction, $validation['error']);
            }

            $validated = $validation['records'];
            if (empty($validated)) {
                return $this->rollBack($useTransaction, self::failure('No valid records to create', Refusal::INVALID_INPUT));
            }

            // The manager refuses a duplicate on insert; on the API backend that would
            // land after the old set is gone, so refuse a repeated content up front.
            $contents = array_column($validated, 'content');
            if (count($contents) !== count(array_unique($contents))) {
                return $this->rollBack($useTransaction, self::failure('A record with this hostname, type, and content already exists', Refusal::CONFLICT));
            }

            $existing = $this->recordRepository->getRRSetRecords($zoneId, $fqdn, $type);

            // A replace with the stored set would still make PowerDNS bump the serial on
            // the API backend, so an install that opted out of bumping skips the writes
            if (!$this->config->get('dns', 'bump_serial_on_unchanged_save', true) && self::sameRecordSet($existing, $validated)) {
                if ($useTransaction) {
                    $this->transaction->commit();
                }
                $this->audit->logApiRrsetReplace($zoneId, $normalizedName, $type, count($validated));

                return ['success' => true, 'message' => 'RRSet replaced successfully', 'name' => $normalizedName, 'records' => $validated];
            }

            foreach ($existing as $record) {
                if (!$this->recordManager->deleteRecord($record['id'], false)->success) {
                    return $this->rollBack($useTransaction, self::failure('Failed to delete existing record with ID ' . $record['id'], Refusal::BACKEND_FAILURE));
                }
            }

            // The manager writes the change log per record; the serial and rectify happen once below
            $recordsCreated = 0;
            foreach ($validated as $vr) {
                $created = $this->recordManager->addRecordGetId($zoneId, $normalizedName, $type, $vr['content'], $vr['ttl'], $vr['priority'], $vr['disabled'], false);
                if (!$created->success) {
                    return $this->rollBack($useTransaction, self::failure((string)$created->message, $created->refusal) + ['write' => $created, 'content' => $vr['content']]);
                }
                $recordsCreated++;
            }

            if ($type !== 'SOA') {
                $this->soaRecordManager->updateSOASerial($zoneId);
            }
            if ($useTransaction) {
                $this->transaction->commit();
            }
            $this->recordManager->finalizeZone($zoneId, false);

            $this->audit->logApiRrsetReplace($zoneId, $normalizedName, $type, $recordsCreated);
        } catch (Throwable $e) {
            if ($useTransaction) {
                $this->transaction->rollBack();
            }
            throw $e;
        }

        return [
            'success' => true,
            'message' => 'RRSet replaced successfully',
            'name' => $normalizedName,
            'records' => $validated,
        ];
    }

    /**
     * @return array{records: list<array{content: string, ttl: int, priority: int, disabled: int}>, error: array{success: false, message: string, refusal: Refusal}|null}
     */
    private function validateRecords(int $zoneId, string $normalizedName, string $type, int $ttl, array $records): array
    {
        $hostmaster = $this->config->get('dns', 'hostmaster');
        $defaultTtl = (int)$this->config->get('dns', 'ttl');

        $validated = [];
        foreach ($records as $record) {
            $result = $this->validationService->validateRecord(-1, $zoneId, $type, $record['content'], $normalizedName, $record['priority'], $ttl, $hostmaster, $defaultTtl);
            if (!$result->isValid()) {
                return ['records' => [], 'error' => self::failure($result->getFirstError(), Refusal::INVALID_INPUT)];
            }

            $data = $result->getData();
            $validated[] = [
                'content' => $data['content'] ?? $record['content'],
                'ttl' => $data['ttl'] ?? $ttl,
                'priority' => $data['prio'] ?? $record['priority'],
                'disabled' => $record['disabled'],
            ];
        }

        return ['records' => $validated, 'error' => null];
    }

    private function rollBack(bool $useTransaction, array $failure): array
    {
        if ($useTransaction) {
            $this->transaction->rollBack();
        }

        return $failure;
    }

    /**
     * Whether the validated set equals the stored one in every field PowerDNS serves.
     *
     * @param array<int, array<string, mixed>> $existing Stored rows of the RRSet
     * @param list<array{content: string, ttl: int, priority: int, disabled: int}> $validated
     */
    private static function sameRecordSet(array $existing, array $validated): bool
    {
        $key = static fn(mixed $content, mixed $ttl, mixed $prio, mixed $disabled): string
            => implode("\0", [(string)$content, (int)$ttl, (int)$prio, (int)$disabled]);

        $stored = array_map(fn(array $r): string => $key($r['content'] ?? '', $r['ttl'] ?? 0, $r['prio'] ?? 0, $r['disabled'] ?? 0), $existing);
        $wanted = array_map(fn(array $r): string => $key($r['content'], $r['ttl'], $r['priority'], $r['disabled']), $validated);
        sort($stored);
        sort($wanted);

        return $stored === $wanted;
    }

    /**
     * @return array{success: false, message: string, refusal: Refusal}
     */
    private static function failure(string $message, Refusal $refusal): array
    {
        return ['success' => false, 'message' => $message, 'refusal' => $refusal];
    }
}
