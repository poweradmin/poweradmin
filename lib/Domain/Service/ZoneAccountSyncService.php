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

namespace Poweradmin\Domain\Service;

use PDO;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Service for mirroring zone ownership into the PowerDNS account field
 */
class ZoneAccountSyncService
{
    private PDO $db;
    private ConfigurationInterface $config;
    private ?DnsBackendProvider $backendProvider;

    public function __construct(PDO $db, ConfigurationInterface $config, ?DnsBackendProvider $backendProvider = null)
    {
        $this->db = $db;
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

        $stmt = $this->db->prepare("
            SELECT u.username
            FROM users u
            INNER JOIN zones z ON z.owner = u.id
            WHERE z.domain_id = ?
            ORDER BY z.id
            LIMIT 1
        ");
        $stmt->execute([$domainId]);
        $account = $stmt->fetchColumn();

        $this->pushZoneAccount($domainId, $account === false ? null : (string)$account);
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
