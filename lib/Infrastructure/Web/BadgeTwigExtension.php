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

namespace Poweradmin\Infrastructure\Web;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class BadgeTwigExtension extends AbstractExtension
{
    private const RECORD_TYPE_CLASSES = [
        'A' => 'bg-primary',
        'AAAA' => 'bg-info',
        'CNAME' => 'bg-success',
        'MX' => 'bg-warning',
        'TXT' => 'bg-secondary',
        'PTR' => 'bg-danger',
        'SOA' => 'bg-dark',
        'NS' => 'bg-info',
    ];

    private const ZONE_TYPE_CLASSES = [
        'MASTER' => 'bg-success',
        'SLAVE' => 'bg-info',
        'NATIVE' => 'bg-warning',
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('record_type_class', [$this, 'getRecordTypeClass']),
            new TwigFunction('zone_type_class', [$this, 'getZoneTypeClass']),
        ];
    }

    public function getRecordTypeClass(string $type): string
    {
        return self::RECORD_TYPE_CLASSES[strtoupper($type)] ?? 'bg-secondary';
    }

    public function getZoneTypeClass(string $type): string
    {
        return self::ZONE_TYPE_CLASSES[strtoupper($type)] ?? 'bg-secondary';
    }
}
