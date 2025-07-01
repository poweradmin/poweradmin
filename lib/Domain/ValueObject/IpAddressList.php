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
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;

class IpAddressList
{
    private readonly array $ipv4Addresses;
    private readonly array $ipv6Addresses;

    public function __construct(array $ipv4Addresses = [], array $ipv6Addresses = [])
    {
        $ipValidator = new IPAddressValidator();

        foreach ($ipv4Addresses as $ip) {
            if (!$ipValidator->isValidIPv4($ip)) {
                throw new InvalidArgumentException("Invalid IPv4 address: {$ip}");
            }
        }

        foreach ($ipv6Addresses as $ip) {
            if (!$ipValidator->isValidIPv6($ip)) {
                throw new InvalidArgumentException("Invalid IPv6 address: {$ip}");
            }
        }

        $this->ipv4Addresses = array_values(array_unique($ipv4Addresses));
        $this->ipv6Addresses = array_values(array_unique($ipv6Addresses));
    }

    public static function fromCommaSeparatedStrings(string $ipv4String, string $ipv6String): self
    {
        $ipValidator = new IPAddressValidator();

        $ipv4List = [];
        if (!empty($ipv4String)) {
            $ipv4Array = array_map('trim', explode(',', $ipv4String));
            $ipv4List = array_values(array_filter($ipv4Array, function ($ip) use ($ipValidator) {
                return !empty($ip) && $ipValidator->isValidIPv4($ip);
            }));
        }

        $ipv6List = [];
        if (!empty($ipv6String)) {
            $ipv6Array = array_map('trim', explode(',', $ipv6String));
            $ipv6List = array_values(array_filter($ipv6Array, function ($ip) use ($ipValidator) {
                return !empty($ip) && $ipValidator->isValidIPv6($ip);
            }));
        }

        return new self($ipv4List, $ipv6List);
    }

    public function getIpv4Addresses(): array
    {
        return $this->ipv4Addresses;
    }

    public function getIpv6Addresses(): array
    {
        return $this->ipv6Addresses;
    }

    public function getAllAddresses(): array
    {
        return array_merge($this->ipv4Addresses, $this->ipv6Addresses);
    }

    public function hasIpv4Addresses(): bool
    {
        return !empty($this->ipv4Addresses);
    }

    public function hasIpv6Addresses(): bool
    {
        return !empty($this->ipv6Addresses);
    }

    public function hasAnyAddresses(): bool
    {
        return $this->hasIpv4Addresses() || $this->hasIpv6Addresses();
    }

    public function isEmpty(): bool
    {
        return !$this->hasAnyAddresses();
    }

    public function getAddressesByType(string $recordType): array
    {
        return match ($recordType) {
            RecordType::A => $this->ipv4Addresses,
            RecordType::AAAA => $this->ipv6Addresses,
            default => []
        };
    }

    public function getSortedIpv4Addresses(): array
    {
        $sorted = $this->ipv4Addresses;
        sort($sorted);
        return $sorted;
    }

    public function getSortedIpv6Addresses(): array
    {
        $sorted = $this->ipv6Addresses;
        sort($sorted);
        return $sorted;
    }
}
