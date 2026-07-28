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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Service\PermissionTemplateContentGuard;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;

/**
 * The single guarded entry point for permission template writes.
 *
 * Every web and API create/update path goes through here, so the content rule cannot be
 * skipped by adding another controller.
 */
class PermissionTemplateWriteService
{
    public function __construct(
        private readonly DbPermissionTemplateRepository $templateRepository,
        private readonly UserRepository $userRepository
    ) {
    }

    /**
     * @param array<string, mixed> $details Repository payload: templ_name, templ_descr, template_type, perm_id
     * @return array{success: bool, message: string, status: int}
     */
    public function create(int $callerId, array $details): array
    {
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository,
            $callerId,
            null,
            $this->submittedPermIds($details)
        );

        if ($error !== null) {
            return ['success' => false, 'message' => $error, 'status' => 403];
        }

        if (!$this->templateRepository->addPermissionTemplate($details)) {
            return ['success' => false, 'message' => 'Failed to create permission template', 'status' => 500];
        }

        return ['success' => true, 'message' => 'Permission template created successfully', 'status' => 201];
    }

    /**
     * @param array<string, mixed> $details Repository payload; templ_id is forced to $templateId
     * @return array{success: bool, message: string, status: int}
     */
    public function update(int $callerId, int $templateId, array $details): array
    {
        $error = PermissionTemplateContentGuard::apply(
            $this->userRepository,
            $callerId,
            $templateId,
            $this->submittedPermIds($details)
        );

        if ($error !== null) {
            return ['success' => false, 'message' => $error, 'status' => 403];
        }

        // The guarded id wins, so a caller-supplied templ_id can never target another row.
        $details['templ_id'] = $templateId;

        if (!$this->templateRepository->updatePermissionTemplateDetails($details)) {
            return ['success' => false, 'message' => 'Failed to update permission template', 'status' => 500];
        }

        return ['success' => true, 'message' => 'Permission template updated successfully', 'status' => 200];
    }

    /**
     * Permission ids the write would persist, or null when it leaves contents untouched.
     *
     * @param array<string, mixed> $details
     * @return ?array<mixed>
     */
    private function submittedPermIds(array $details): ?array
    {
        if (!array_key_exists('perm_id', $details)) {
            return null;
        }

        return is_array($details['perm_id']) ? $details['perm_id'] : [];
    }

    /**
     * Whether the caller may be offered the superuser permission in a template picker.
     */
    public function callerMaySetSuperuser(int $callerId): bool
    {
        return $this->userRepository->hasAdminPermission($callerId);
    }
}
