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
use Poweradmin\Domain\Database\DbCompat;
use Poweradmin\Domain\Repository\ExternalIdentityRepositoryInterface;

/**
 * SQL persistence for the oidc_user_links and saml_user_links tables.
 */
final class DbExternalIdentityRepository implements ExternalIdentityRepositoryInterface
{
    private PDO $db;

    /** Collation clause forcing byte-exact matches on subject lookups. */
    private string $binaryCollation;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->binaryCollation = DbCompat::binaryCollation((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function findUserIdByOidcSubject(string $subject, string $providerId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT user_id FROM oidc_user_links
            WHERE oidc_subject{$this->binaryCollation} = ? AND provider_id{$this->binaryCollation} = ?
        ");
        $stmt->execute([$subject, $providerId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int)$result['user_id'] : null;
    }

    public function findUserIdBySamlSubject(string $subject, string $providerId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT user_id FROM saml_user_links
            WHERE saml_subject{$this->binaryCollation} = ? AND provider_id{$this->binaryCollation} = ?
        ");
        $stmt->execute([$subject, $providerId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int)$result['user_id'] : null;
    }

    public function linkOidc(int $userId, string $providerId, string $subject, string $username, string $email): void
    {
        $stmt = $this->db->prepare("
            SELECT id FROM oidc_user_links
            WHERE user_id = ? AND provider_id = ?
        ");
        $stmt->execute([$userId, $providerId]);

        if ($stmt->fetch()) {
            $stmt = $this->db->prepare("
                UPDATE oidc_user_links
                SET oidc_subject = ?, username = ?, email = ?, updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ? AND provider_id = ?
            ");
            $stmt->execute([$subject, $username, $email, $userId, $providerId]);
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO oidc_user_links
            (user_id, provider_id, oidc_subject, username, email, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$userId, $providerId, $subject, $username, $email]);
    }

    public function linkSaml(int $userId, string $providerId, string $subject, string $username, string $email): void
    {
        $stmt = $this->db->prepare("
            SELECT id FROM saml_user_links
            WHERE user_id = ? AND provider_id = ?
        ");
        $stmt->execute([$userId, $providerId]);

        if ($stmt->fetch()) {
            $stmt = $this->db->prepare("
                UPDATE saml_user_links
                SET saml_subject = ?, username = ?, email = ?, updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ? AND provider_id = ?
            ");
            $stmt->execute([$subject, $username, $email, $userId, $providerId]);
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO saml_user_links
            (user_id, provider_id, saml_subject, username, email, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$userId, $providerId, $subject, $username, $email]);
    }

    public function findOrphanedOidcLinks(): array
    {
        $stmt = $this->db->prepare("
            SELECT oul.id, oul.user_id, oul.provider_id, oul.username
            FROM oidc_user_links oul
            LEFT JOIN users u ON oul.user_id = u.id
            WHERE u.id IS NULL
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteOrphanedOidcLinks(): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM oidc_user_links
            WHERE user_id NOT IN (SELECT id FROM users)
        ");
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function findOrphanedOidcLinkIds(string $subject, string $providerId): array
    {
        $stmt = $this->db->prepare("
            SELECT oul.id
            FROM oidc_user_links oul
            LEFT JOIN users u ON oul.user_id = u.id
            WHERE oul.oidc_subject{$this->binaryCollation} = ? AND oul.provider_id{$this->binaryCollation} = ? AND u.id IS NULL
        ");
        $stmt->execute([$subject, $providerId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function findOrphanedSamlLinkIds(string $subject, string $providerId): array
    {
        $stmt = $this->db->prepare("
            SELECT sul.id
            FROM saml_user_links sul
            LEFT JOIN users u ON sul.user_id = u.id
            WHERE sul.saml_subject{$this->binaryCollation} = ? AND sul.provider_id{$this->binaryCollation} = ? AND u.id IS NULL
        ");
        $stmt->execute([$subject, $providerId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function deleteOidcLinks(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("DELETE FROM oidc_user_links WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));
    }

    public function deleteSamlLinks(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("DELETE FROM saml_user_links WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));
    }
}
