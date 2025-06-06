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

use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

class RecordLog
{

    private $record_prior;
    private $record_after;

    private bool $record_changed = false;
    private LegacyLogger $logger;
    private PDOCommon $db;

    private ConfigurationManager $config;

    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = new LegacyLogger($db);
    }

    public function logPrior($rid, $zid, $comment): void
    {
        $this->record_prior = $this->getRecord($rid);
        $this->record_prior['zid'] = $zid;
        $this->record_prior['comment'] = $comment;
    }

    public function logAfter($rid): void
    {
        $this->record_after = $this->getRecord($rid);
    }

    private function getRecord($rid): array|int
    {
        $dnsRecord = new DnsRecord($this->db, $this->config);
        return $dnsRecord->getRecordFromId($rid);
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
        $record_prior_copy = $this->record_prior;

        // PowerDNS only searches for lowercase records
        $record_copy['name'] = strtolower($record_copy['name']);
        $record_prior_copy['name'] = strtolower($record_prior_copy['name']);

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
        $this->logger->logInfo(sprintf(
            'client_ip:%s user:%s operation:edit_record'
            . ' old_record_type:%s old_record:%s old_content:%s old_ttl:%s old_priority:%s'
            . ' record_type:%s record:%s content:%s ttl:%s priority:%s',
            $_SERVER['REMOTE_ADDR'],
            $_SESSION["userlogin"],
            $this->record_prior['type'],
            $this->record_prior['name'],
            $this->record_prior['content'],
            $this->record_prior['ttl'],
            $this->record_prior['prio'],
            $this->record_after['type'],
            $this->record_after['name'],
            $this->record_after['content'],
            $this->record_after['ttl'],
            $this->record_after['prio']
        ), $this->record_prior['zid']);
    }
}
