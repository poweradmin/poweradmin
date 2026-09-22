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

namespace Poweradmin\Application\Console\Command;

use InvalidArgumentException;
use Poweradmin\Application\Console\Arguments;
use Poweradmin\Application\Console\CommandInterface;
use Poweradmin\Application\Console\TableWriter;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordListingInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;

/**
 * zone:show <id|name> - the records of one zone, one row per record, in the
 * order the web zone editor shows them. The actor needs the same view
 * permission as for opening the zone in the browser; a refusal exits 1.
 */
final class ZoneShowCommand implements CommandInterface
{
    public const NAME = 'zone:show';
    public const COLUMNS = ['ID', 'NAME', 'TYPE', 'CONTENT', 'TTL', 'PRIO', 'DISABLED'];

    private PermissionService $permissionService;
    private DomainRepositoryInterface $domainRepository;
    private RecordListingInterface $records;

    public function __construct(
        PermissionService $permissionService,
        DomainRepositoryInterface $domainRepository,
        RecordListingInterface $records
    ) {
        $this->permissionService = $permissionService;
        $this->domainRepository = $domainRepository;
        $this->records = $records;
    }

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Print the records of the zone given by id or name (id, name, type, content, ttl, prio, disabled)';
    }

    public static function options(): array
    {
        return ['user', TableWriter::OPTION];
    }

    public function run(Arguments $arguments, ActorInterface $actor, $stdout, $stderr): int
    {
        $positionals = $arguments->positionals();
        if (count($positionals) !== 1) {
            throw new InvalidArgumentException(self::NAME . ' expects exactly one argument: the zone id or name');
        }
        $writer = TableWriter::fromArguments($arguments, $stdout);

        $zoneId = $this->resolveZoneId($positionals[0]);
        if ($zoneId === null) {
            fwrite($stderr, sprintf("Zone \"%s\" does not exist.\n", $positionals[0]));
            return 1;
        }

        $userId = $actor->userId();
        if ($userId === null || !$this->permissionService->canViewZone($userId, $zoneId)) {
            fwrite($stderr, sprintf("The acting user may not view zone %d; pass --as-user=<id> to act as a user who may.\n", $zoneId));
            return 1;
        }

        $rows = [];
        foreach ($this->records->getRecordsFromDomainId($zoneId) as $record) {
            // The API backend keys records by an encoded string, so only a numeric id is cast
            $id = $record['id'];
            $rows[] = [
                is_numeric($id) ? (int) $id : (string) $id,
                (string) $record['name'],
                (string) $record['type'],
                (string) $record['content'],
                (int) $record['ttl'],
                (int) ($record['prio'] ?? 0),
                (bool) ($record['disabled'] ?? false),
            ];
        }
        $writer->write(self::COLUMNS, $rows);

        return 0;
    }

    private function resolveZoneId(string $zone): ?int
    {
        if (ctype_digit($zone)) {
            return $this->domainRepository->zoneIdExists((int) $zone) ? (int) $zone : null;
        }

        return $this->domainRepository->getDomainIdByName($zone);
    }
}
