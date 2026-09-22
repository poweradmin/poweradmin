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

use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Port\ZoneWriteBackendInterface;
use Poweradmin\Domain\Repository\ZoneAccountOwnerLookupInterface;

/**
 * Mirrors zone ownership into the PowerDNS account field.
 */
class ZoneAccountSyncService
{
    private ZoneAccountOwnerLookupInterface $owners;
    private ConfigurationInterface $config;
    private (ZoneWriteBackendInterface&BackendCapabilitiesInterface)|null $backendProvider;

    public function __construct(ZoneAccountOwnerLookupInterface $owners, ConfigurationInterface $config, (ZoneWriteBackendInterface&BackendCapabilitiesInterface)|null $backendProvider = null)
    {
        $this->owners = $owners;
        $this->config = $config;
        $this->backendProvider = $backendProvider;
    }

    /**
     * Whether owner-to-account sync is active (opt-in via dns.sync_zone_owner_to_account)
     */
    public function isEnabled(): bool
    {
        return $this->backendProvider !== null && (bool)$this->config->get('dns', 'sync_zone_owner_to_account', false);
    }

    /**
     * Update the zone's PowerDNS account field with the oldest owner's username
     *
     * @param int $domainId Domain ID
     */
    public function syncZoneAccount(int $domainId): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->pushZoneAccount($domainId, $this->owners->oldestOwnerUsername($domainId));
    }

    /**
     * Push a resolved owner username to the backend, for callers with their
     * own ownership lookup. Zones without a direct owner get an empty account
     * so removed users don't linger.
     *
     * @param int $domainId Domain ID
     * @param string|null $username Owner username, or null when the zone has no direct owner
     */
    public function pushZoneAccount(int $domainId, ?string $username): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->backendProvider->updateZoneAccount($domainId, $username ?? '');
    }
}
