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

namespace Poweradmin\Domain\Service\Zone;

use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Outcome of a DnssecKeyService request, the key or keys it concerns, and the
 * accepted values when a new key was refused as invalid.
 */
final readonly class DnssecKeyResult
{
    /** Null when the request went through */
    public ?Refusal $refusal;

    /**
     * @param CryptoKey|null $key The key found, created or changed; on a failed change, the key as it still is
     * @param CryptoKey[] $keys The zone's keys, for a listing
     * @param list<string> $allowedAlgorithms The algorithms the server accepts, when the algorithm was refused
     * @param list<int> $acceptedBits The sizes the algorithm accepts, when the size was refused
     */
    public function __construct(
        public DnssecKeyOutcome $outcome,
        public ?CryptoKey $key = null,
        public array $keys = [],
        public array $allowedAlgorithms = [],
        public array $acceptedBits = []
    ) {
        $this->refusal = $outcome->refusal();
    }
}
