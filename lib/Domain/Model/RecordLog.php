<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Domain\Model;

use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\UserContextService;
use PDO;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

class RecordLog
{
    private ?array $record_prior = null;
    private ?array $record_after = null;

    private bool $record_changed = false;
    private LegacyLogger $logger;
    private RecordRepositoryInterface $recordRepository;
    private IpAddressRetriever $ipAddressRetriever;
    private UserContextService $userContextService;

    public function __construct(PDO $db, RecordRepositoryInterface $recordRepository)
    {
        $this->recordRepository = $recordRepository;
        $this->logger = new LegacyLogger($db);
        $this->ipAddressRetriever = new IpAddressRetriever($_SERVER);
        $this->userContextService = new UserContextService();
    }

    public function logPrior($rid, $zid, $comment): void
    {
        $this->record_prior = $this->getRecord($rid);
        // Log against the record's real zone, not a caller-supplied id that may
        // point at a different zone the caller happens to own.
        $this->record_prior['zid'] = $this->record_prior['domain_id'] ?? $zid;
        $this->record_prior['comment'] = $comment;
    }

    public function logAfter($rid, ?array $record = null): void
    {
        $this->record_after = $this->getRecord($rid);

        // With the API backend a record's encoded ID changes when its name or
        // content is edited, so looking it up by the original ID after the edit
        // returns nothing. Fall back to the submitted values so the audit log
        // stays complete and write() does not dereference a null record.
        if ($this->record_after === null && $record !== null) {
            $this->record_after = [
                'type' => $record['type'] ?? '',
                'name' => $record['name'] ?? '',
                'content' => $record['content'] ?? '',
                'ttl' => $record['ttl'] ?? '',
                'prio' => $record['prio'] ?? '',
            ];
        }
    }

    protected function getRecord(int|string $rid): ?array
    {
        return $this->recordRepository->getRecordFromId($rid);
    }

    public function getRecordCopy(): array
    {
        return $this->record_prior;
    }

    public function hasChanged(array $record): bool
    {
        // Arrays are assigned by copy.
        // Copy arrays to avoid side effects caused by unset().
        $record_copy = $record;
        $record_prior_copy = $this->record_prior ?? [];

        // PowerDNS only searches for lowercase records. The prior record can lack a
        // name with the API backend (the encoded id no longer resolves), so guard
        // both sides against null before lowercasing.
        $record_copy['name'] = strtolower($record_copy['name'] ?? '');
        $record_prior_copy['name'] = strtolower($record_prior_copy['name'] ?? '');

        // Make $record_copy and $record_prior_copy compatible
        $record_copy['id'] = $record_copy['rid'];
        $record_copy['domain_id'] = $record_copy['zid'];
        unset($record_copy['rid']);

        // Do the comparison
        $this->record_changed = !empty(array_diff_assoc($record_copy, $record_prior_copy));
        return $this->record_changed;
    }

    public function write(): void
    {
        $this->logger->logInfo($this->buildLogMessage(), $this->record_prior['zid'] ?? null);
    }

    protected function buildLogMessage(): string
    {
        $prior = $this->record_prior ?? [];
        $after = $this->record_after ?? [];

        return sprintf(
            'client_ip:%s user:%s operation:edit_record'
            . ' old_record_type:%s old_record:%s old_content:%s old_ttl:%s old_priority:%s'
            . ' record_type:%s record:%s content:%s ttl:%s priority:%s',
            $this->ipAddressRetriever->getClientIp(),
            $this->userContextService->getLoggedInUsername(),
            $prior['type'] ?? '',
            $prior['name'] ?? '',
            $prior['content'] ?? '',
            $prior['ttl'] ?? '',
            $prior['prio'] ?? '',
            $after['type'] ?? '',
            $after['name'] ?? '',
            $after['content'] ?? '',
            $after['ttl'] ?? '',
            $after['prio'] ?? ''
        );
    }
}
