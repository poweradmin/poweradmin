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

namespace Poweradmin\Domain\Repository;

/**
 * Persistence for the OIDC and SAML identity links that tie a provider subject to a local user.
 */
interface ExternalIdentityRepositoryInterface
{
    /**
     * The user id linked to an OIDC subject at a provider, matched byte-exact.
     */
    public function findUserIdByOidcSubject(string $subject, string $providerId): ?int;

    /**
     * The user id linked to a SAML NameID at a provider, matched byte-exact.
     */
    public function findUserIdBySamlSubject(string $subject, string $providerId): ?int;

    /**
     * Create the OIDC link for a user at a provider, or refresh it when one exists.
     */
    public function linkOidc(int $userId, string $providerId, string $subject, string $username, string $email): void;

    /**
     * Create the SAML link for a user at a provider, or refresh it when one exists.
     */
    public function linkSaml(int $userId, string $providerId, string $subject, string $username, string $email): void;

    /**
     * OIDC links whose user no longer exists.
     *
     * @return array<int, array{id: int|string, user_id: int|string, provider_id: string, username: string}>
     */
    public function findOrphanedOidcLinks(): array;

    /**
     * Delete every OIDC link whose user no longer exists.
     *
     * @return int Number of links deleted
     */
    public function deleteOrphanedOidcLinks(): int;

    /**
     * Ids of the OIDC links for this subject whose user no longer exists.
     *
     * @return array<int, int>
     */
    public function findOrphanedOidcLinkIds(string $subject, string $providerId): array;

    /**
     * Ids of the SAML links for this subject whose user no longer exists.
     *
     * @return array<int, int>
     */
    public function findOrphanedSamlLinkIds(string $subject, string $providerId): array;

    /**
     * @param array<int, int> $ids
     */
    public function deleteOidcLinks(array $ids): void;

    /**
     * @param array<int, int> $ids
     */
    public function deleteSamlLinks(array $ids): void;
}
