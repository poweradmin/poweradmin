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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\UserFormMessages;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Constants;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\Service\SessionKeys;

/**
 * Handles the delete-user page: deletes the user and transfers or deletes each of their zones as chosen.
 */
class DeleteUserController extends BaseController
{
    // Above this many total <option> elements the owner dropdowns are rendered
    // lazily, since zones x users options can exhaust the PHP memory limit
    private const MAX_INLINE_OWNER_OPTIONS = 10000;

    public function __construct(array $request)
    {
        parent::__construct($request);
    }

    public function run(): void
    {
        $perm_edit_others = $this->hasPermission('user_edit_others');
        $perm_is_godlike = $this->hasPermission('user_is_ueberuser');

        $uid = $this->getSafeRequestValue('id');
        if (!$uid || !Validator::isNumber($uid)) {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        // Check basic permissions first
        if (($uid != $_SESSION[SessionKeys::USERID] && !$perm_edit_others) || ($uid == $_SESSION[SessionKeys::USERID] && !$perm_is_godlike)) {
            $this->showError(_("You do not have the permission to delete this user."));
        }

        // Prevent non-superusers from deleting superuser accounts (privilege escalation protection)
        $targetIsSuperuser = $this->createPermissionService()->isAdmin((int)$uid);

        if ($targetIsSuperuser && !$perm_is_godlike) {
            $this->showError(_('You do not have permission to delete a superuser account.'));
        }

        // All permission checks passed, now handle POST request
        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->deleteUser($uid);
        }

        $this->showQuestion($uid);
    }

    public function deleteUser(string $uid): void
    {
        $target = $this->createUserRepository()->getUserById((int)$uid);
        if ($target === null) {
            $this->showError(_('User does not exist.'));
        }
        // Captured before deletion since the user won't exist after
        $targetUsername = (string)$target['username'];

        $zones = array();
        $zone = $this->httpRequest->getPostParam('zone');
        if (is_string($zone)) {
            // Per-zone decisions arrive as one JSON field to stay under PHP's max_input_vars limit
            $parsed = self::parseZoneDecisions($zone);
            if ($parsed === null) {
                $this->showError(_('Invalid or unexpected input given.'));
                return;
            }
            $zones = $parsed;
        } elseif (is_array($zone)) {
            // No-JS fallback posts several fields per zone; a missing trailing marker means
            // the POST was truncated, so abort instead of mishandling the dropped zones
            if ($this->httpRequest->getPostParam('form_complete') === null) {
                $this->setMessage('delete_user', 'error', _('The user was not deleted because the form exceeded the server limit on the number of fields. Ask your administrator to increase the PHP "max_input_vars" setting.'));
                return;
            }
            // Reject reassignments without a valid owner (lazily rendered selects post empty without JS)
            foreach ($zone as $zoneEntry) {
                if (is_array($zoneEntry) && ($zoneEntry['target'] ?? '') === 'new_owner' && (int)($zoneEntry['newowner'] ?? 0) <= 0) {
                    $this->showError(_('Invalid or unexpected input given.'));
                    return;
                }
            }
            $zones = $zone;
        }

        $deleted = $this->createUserManagementService()->deleteUserWithZoneDecisions((int)$this->getCurrentUserId(), (int)$uid, $zones);
        if (!$deleted['success']) {
            $this->setMessage('delete_user', 'error', UserFormMessages::deleteErrorMessage($deleted));
            return;
        }

        $this->createAuditService()->logUserDelete($targetUsername);

        $this->setMessage('users', 'success', _('The user has been deleted successfully.'));
        $this->redirect('/users');
    }

    /**
     * Parses the JSON zone-decision field into the per-zone array shape used by
     * UserManagementService::deleteUserWithZoneDecisions(). Returns null when the payload is malformed.
     */
    public static function parseZoneDecisions(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $zones = [];
        foreach ($decoded as $decision) {
            if (!is_array($decision)) {
                return null;
            }
            $zid = (int)($decision['zid'] ?? 0);
            $target = $decision['target'] ?? '';
            if ($zid <= 0 || !in_array($target, ['delete', 'new_owner'], true)) {
                return null;
            }
            $zone = ['zid' => $zid, 'target' => $target];
            if ($target === 'new_owner') {
                $newOwner = (int)($decision['newowner'] ?? 0);
                if ($newOwner <= 0) {
                    return null;
                }
                $zone['newowner'] = $newOwner;
            }
            $zones[$zid] = $zone;
        }
        return array_values($zones);
    }

    public function showQuestion(string $uid): void
    {
        $user = $this->createUserRepository()->getUserById((int)$uid);
        $name = ($user['fullname'] ?? '') ?: ($user['username'] ?? '');
        $repositoryFactory = $this->getRepositoryFactory();
        $domainRepository = $repositoryFactory->createDomainRepository();
        // The reassignment list renders neither health badges nor record counts
        $zones = $domainRepository->getZones("own", (int)$uid, 'all', 0, Constants::DEFAULT_MAX_ROWS, 'name', 'ASC', false, null, null, false, false);

        $users = [];
        if (count($zones) > 0) {
            $users = $this->createUserRepository()->getUsersWithZoneCounts();
        }

        $this->render('delete_user.html', [
            'name' => $name,
            'uid' => $uid,
            'zones' => $zones,
            'zones_count' => count($zones),
            'users' => $users,
            'lazy_owner_options' => count($zones) * count($users) > self::MAX_INLINE_OWNER_OPTIONS,
        ]);
    }
}
