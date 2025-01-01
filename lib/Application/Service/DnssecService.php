<?php

namespace Poweradmin\Application\Service;

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

use Poweradmin\Domain\Service\DnssecProvider;

class DnssecService {
    private DnssecProvider $provider;

    public function __construct(DnssecProvider $provider) {
        $this->provider = $provider;
    }

    public function rectifyZone(string $domainName): bool {
        return $this->provider->rectifyZone($domainName);
    }
    public function secureZone(string $domainName): bool {
        return $this->provider->secureZone($domainName);
    }
    public function unsecureZone(string $domainName): bool {
        return $this->provider->unsecureZone($domainName);
    }
    public function isZoneSecured(string $domainName, $config): bool {
        return $this->provider->isZoneSecured($domainName, $config);
    }
    public function getDsRecords(string $domainName): array {
        return $this->provider->getDsRecords($domainName);
    }
    public function getDnsKeyRecords(string $domainName): array {
        return $this->provider->getDnsKeyRecords($domainName);
    }
    public function activateZoneKey(string $domainName, int $keyId): bool {
        return $this->provider->activateZoneKey($domainName, $keyId);
    }
    public function deactivateZoneKey(string $domainName, int $keyId): bool {
        return $this->provider->deactivateZoneKey($domainName, $keyId);
    }
    public function getKeys(string $domainName): array {
        return $this->provider->getKeys($domainName);
    }
    public function addZoneKey(string $domainName, string $keyType, int $keySize, string $algorithm): bool {
        return $this->provider->addZoneKey($domainName, $keyType, $keySize, $algorithm);
    }
    public function removeZoneKey(string $domainName, int $keyId): bool {
        return $this->provider->removeZoneKey($domainName, $keyId);
    }
    public function keyExists(string $domainName, int $keyId): bool {
        return $this->provider->keyExists($domainName, $keyId);
    }
    public function getZoneKey(string $domainName, int $keyId): array {
        return $this->provider->getZoneKey($domainName, $keyId);
    }
}