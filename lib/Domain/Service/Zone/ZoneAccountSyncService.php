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
     * @param int|null $excludeUserId A user to leave out, for a zone about to lose them as an owner
     */
    public function syncZoneAccount(int $domainId, ?int $excludeUserId = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->pushZoneAccount($domainId, $this->owners->oldestOwnerUsername($domainId, $excludeUserId));
    }

    /**
     * Replace a username that was just renamed away on every zone still carrying it. With
     * the owner sync on the oldest owner is pushed, else the new name (a renamed user is not
     * a removed one); with it off the new name is written straight over the old. Zones with
     * another account, set by hand, stay as they are. A failing zone does not stop the rest;
     * the first failure is rethrown at the end.
     */
    public function followRenamedUsername(string $oldUsername, string $newUsername): void
    {
        if ($this->backendProvider === null || $oldUsername === '' || $newUsername === '') {
            return;
        }

        $failure = null;
        foreach ($this->backendProvider->getZoneIdsByAccount($oldUsername) as $domainId) {
            try {
                if ($this->isEnabled()) {
                    $this->pushZoneAccount($domainId, $this->owners->oldestOwnerUsername($domainId) ?? $newUsername);
                } else {
                    $this->backendProvider->updateZoneAccount($domainId, $newUsername);
                }
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Push a resolved owner username to the backend, for callers with their
     * own ownership lookup. Zones without a direct owner get an empty account
     * so removed users don't linger; a renamed user is not a removed one, see
     * followRenamedUsername().
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
