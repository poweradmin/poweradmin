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

namespace Poweradmin\Application\Service\Zone;

/**
 * What ZoneCreateService made of a batch: either a refusal that stopped the
 * batch before any zone was tried, or one outcome per submitted name.
 */
final readonly class ZoneBatchCreateOutcome
{
    /**
     * @param string|null $message The batch-level refusal in the user's language, null when the names were tried
     * @param list<ZoneCreateOutcome> $outcomes One outcome per name, in submission order
     */
    private function __construct(public ?string $message, public array $outcomes)
    {
    }

    public static function refused(string $message): self
    {
        return new self($message, []);
    }

    /** @param list<ZoneCreateOutcome> $outcomes */
    public static function tried(array $outcomes): self
    {
        return new self(null, $outcomes);
    }

    /** @return list<string> The names that were created */
    public function added(): array
    {
        $added = [];
        foreach ($this->outcomes as $outcome) {
            if ($outcome->success) {
                $added[] = $outcome->zoneName;
            }
        }

        return $added;
    }

    /** @return list<array{name: string, reason: string}> The names that were refused, with the reason */
    public function failed(): array
    {
        $failed = [];
        foreach ($this->outcomes as $outcome) {
            if (!$outcome->success) {
                $failed[] = ['name' => $outcome->zoneName, 'reason' => (string)$outcome->message];
            }
        }

        return $failed;
    }
}
