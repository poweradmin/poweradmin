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
 *
 */

namespace Poweradmin\Infrastructure\Network;

use Pdp\CannotProcessHost;
use Pdp\Domain;
use Pdp\Rules;
use Poweradmin\Domain\Port\PublicSuffixInterface;

/**
 * Public suffix lookups backed by the IANA list in data/public_suffix_list.dat.
 */
final class PdpPublicSuffixList implements PublicSuffixInterface
{
    private const LIST_PATH = __DIR__ . '/../../../data/public_suffix_list.dat';

    // Parsing the list costs far more than a lookup, and callers split one name
    // per record, so the parsed rules are built once per process.
    private static ?Rules $rules = null;

    public function split(string $domain): array
    {
        try {
            $result = self::rules()->resolve(Domain::fromIDNA2008($domain));
            $registrable = $result->registrableDomain()->toString();
            $suffix = $result->suffix()->toString();
        } catch (CannotProcessHost) {
            return ['name' => '', 'suffix' => ''];
        }

        if ($registrable === '' || $suffix === '') {
            return ['name' => '', 'suffix' => ''];
        }

        // registrableDomain is "name.suffix"; the caller wants the two halves
        $name = substr($registrable, 0, -(strlen($suffix) + 1));

        return ['name' => $name, 'suffix' => $suffix];
    }

    private static function rules(): Rules
    {
        return self::$rules ??= Rules::fromPath(self::LIST_PATH);
    }
}
