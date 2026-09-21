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

use Poweradmin\Domain\Service\User\PermissionTemplateDeleteResult;

/**
 * Persistence for permission templates and the permission items they bundle.
 */
interface PermissionTemplateRepositoryInterface
{
    /**
     * Add a permission template. Write through PermissionTemplateWriteService; it carries the content guard.
     *
     * @param array $details Permission template details [templ_name,templ_descr,template_type,perm_id]
     * @return bool true on success, false otherwise
     */
    public function addPermissionTemplate(array $details): bool;

    /**
     * Permissions available (template id 0) or assigned to one template.
     *
     * @param int $templ_id Template ID, 0 for every permission
     * @param bool $return_name_only Return names only instead of [id,name,descr] rows
     * @return array array of permissions [id,name,descr] or permission names [name]
     */
    public function getPermissionsByTemplateId(int $templ_id = 0, bool $return_name_only = false): array;

    /**
     * Update permission template details. Write through PermissionTemplateWriteService; it carries the content guard.
     *
     * @param array $details Permission template details; an absent perm_id key leaves the permissions untouched
     * @return bool true on success, false otherwise
     */
    public function updatePermissionTemplateDetails(array $details): bool;

    /**
     * Name, description and type of a template.
     *
     * @return array|false Template details or false if not found
     */
    public function getPermissionTemplateDetails(int $templ_id): array|false;

    /**
     * Whether the template exists and has the expected type.
     */
    public function validateTemplateType(int $templ_id, string $expected_type): bool;

    /**
     * Every permission template, optionally filtered by type.
     *
     * @param string|null $filter_type Filter by template type ('user', 'group', or null for all)
     * @return array array of templates [id, name, descr, template_type]
     */
    public function listPermissionTemplates(?string $filter_type = null): array;

    /**
     * The template with the fewest permissions, excluding superuser templates.
     *
     * @param string|null $templateType Restrict to 'user' or 'group' templates; null = no filter
     * @return int|null Template ID with minimal permissions, or null if none qualify
     */
    public function getMinimalPermissionTemplateId(?string $templateType = null): ?int;

    /**
     * Delete a permission template and its items, unless a user or group still holds it.
     */
    public function deletePermissionTemplate(int $id): PermissionTemplateDeleteResult;
}
