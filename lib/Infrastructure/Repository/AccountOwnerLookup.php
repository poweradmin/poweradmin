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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Config\ConfigurationInterface;

/**
 * Finds the Poweradmin user a PowerDNS account value names, for zones that arrive
 * without an owner (an autoprimary sets the account from its own configuration).
 */
final class AccountOwnerLookup
{
    /** @var array<string, int|null> A first sync can bring thousands of zones naming a few accounts */
    private array $resolved = [];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * A lookup when dns.adopt_zone_owner_from_account is on, null otherwise.
     */
    public static function forConfig(PDO $db, ConfigurationInterface $config): ?self
    {
        return $config->get('dns', 'adopt_zone_owner_from_account', false) ? new self($db) : null;
    }

    /**
     * The id of the user whose username is exactly $account, or null when none is.
     */
    public function userIdFor(string $account): ?int
    {
        $account = trim($account);
        if ($account === '') {
            return null;
        }
        if (array_key_exists($account, $this->resolved)) {
            return $this->resolved[$account];
        }

        $this->resolved[$account] = null;
        $stmt = $this->db->prepare('SELECT id, username FROM users WHERE username = :username');
        $stmt->execute([':username' => $account]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // A case-insensitive collation also returns "Admin" for "admin"; only the exact name counts
            if ($row['username'] === $account) {
                $this->resolved[$account] = (int)$row['id'];
                break;
            }
        }

        return $this->resolved[$account];
    }
}
