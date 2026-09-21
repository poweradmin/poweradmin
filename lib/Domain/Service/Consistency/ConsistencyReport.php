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

namespace Poweradmin\Domain\Service\Consistency;

use Exception;

/**
 * Shapes check results and fix-all tallies the same way for every backend strategy.
 */
final class ConsistencyReport
{
    /**
     * A check result: success with $allClear when there are no findings, otherwise
     * $status with $countedFormat filled with the number of findings.
     *
     * @param list<array<string, mixed>> $findings
     * @return array{status: string, message: string, data: array}
     */
    public static function build(array $findings, string $allClear, string $status, string $countedFormat): array
    {
        if ($findings === []) {
            return ['status' => 'success', 'message' => $allClear, 'data' => []];
        }

        return ['status' => $status, 'message' => sprintf($countedFormat, count($findings)), 'data' => $findings];
    }

    /** @return array{status: string, message: string, data: array} */
    public static function allClear(string $message): array
    {
        return ['status' => 'success', 'message' => $message, 'data' => []];
    }

    /**
     * Apply $repair to every id, counting the outcomes as [$successKey => n, 'failed' => n].
     * An exception from a repair counts as a failure and does not stop the run.
     *
     * @param list<int> $ids
     * @param callable(int): bool $repair
     * @return array<string, int>
     */
    public static function repairEach(array $ids, callable $repair, string $successKey): array
    {
        $succeeded = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $repair($id) ? $succeeded++ : $failed++;
            } catch (Exception $e) {
                $failed++;
            }
        }

        return [$successKey => $succeeded, 'failed' => $failed];
    }

    /**
     * The message for a fix-all outcome from repairEach(): $nothing when there was
     * nothing to repair, $allFormat with the count when every repair succeeded, and
     * $partialFormat with both counts (a warning) otherwise.
     *
     * @param array<string, int> $counts
     * @return array{status: string, message: string}
     */
    public static function tally(array $counts, string $successKey, string $nothing, string $allFormat, string $partialFormat): array
    {
        $succeeded = $counts[$successKey];
        $failed = $counts['failed'];

        if ($succeeded === 0 && $failed === 0) {
            return ['status' => 'success', 'message' => $nothing];
        }
        if ($failed === 0) {
            return ['status' => 'success', 'message' => sprintf($allFormat, $succeeded)];
        }

        return ['status' => 'warning', 'message' => sprintf($partialFormat, $succeeded, $failed)];
    }

    /** The SOA content written when a zone has no SOA record at all. */
    public static function defaultSoaContent(string $zoneName): string
    {
        return sprintf(
            '%s %s %s 28800 7200 604800 86400',
            'ns1.' . $zoneName,
            'hostmaster.' . $zoneName,
            date('Ymd') . '01'
        );
    }

    /**
     * The ids of every finding, for handing to repairEach().
     *
     * @param array{data: array} $result
     * @return list<int>
     */
    public static function findingIds(array $result): array
    {
        return array_map(static fn(array $finding): int => (int)$finding['id'], $result['data']);
    }
}
