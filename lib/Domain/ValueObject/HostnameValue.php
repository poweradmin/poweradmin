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

namespace Poweradmin\Domain\ValueObject;

use InvalidArgumentException;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class HostnameValue
{
    private string $value;

    public function __construct(string $hostname, ?ConfigurationManager $config = null)
    {
        if (empty($hostname)) {
            throw new InvalidArgumentException('Hostname cannot be empty');
        }

        if ($config !== null) {
            $validator = new HostnameValidator($config);
            $result = $validator->validate($hostname);

            if (!$result->isValid()) {
                throw new InvalidArgumentException('Invalid hostname: ' . implode(', ', $result->getErrors()));
            }
        } else {
            $this->basicValidation($hostname);
        }

        $this->value = $hostname;
    }


    private function basicValidation(string $hostname): void
    {
        if (strlen($hostname) > 253) {
            throw new InvalidArgumentException('Hostname too long (max 253 characters)');
        }

        if (preg_match('/[<>\'\";\x00-\x1f\x7f-\xff]/', $hostname)) {
            throw new InvalidArgumentException('Hostname contains invalid characters');
        }

        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $hostname)) {
            throw new InvalidArgumentException('Hostname format is invalid');
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(HostnameValue $other): bool
    {
        return $this->value === $other->value;
    }
}
