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

namespace Poweradmin\Module\SecondaryZoneImport\Controller;

use Poweradmin\Application\Service\Zone\ZoneCreateRequest;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipResolution;

/**
 * Imports a zone from a live primary by creating a secondary, triggering an
 * AXFR pull, and offering a one-click conversion to a primary zone once the
 * records have transferred. API-backend only (see the module class).
 */
class SecondaryZoneImportController extends BaseController
{
    public function run(): void
    {
        $this->checkPermission(Permission::PERM_ZONE_SLAVE_ADD, _('You do not have the permission to import a secondary zone.'));
        $this->setPageTitle(_('Import secondary zone'));

        $blocker = $this->getOwnerOptionsBlocker();
        if ($blocker !== null) {
            $this->showError($blocker);
            return;
        }

        if (!$this->isPost()) {
            $this->showForm();
            return;
        }

        $this->validateCsrfToken();

        if ($this->httpRequest->getPostParam('action') === 'convert') {
            $this->handleConvert();
            return;
        }

        $this->handleImport();
    }

    /**
     * JSON endpoint polled by the import status view to report whether the
     * AXFR transfer has populated the zone yet, so the convert step can tell
     * the user when the records have arrived.
     */
    public function status(): void
    {
        header('Content-Type: application/json');

        $zoneId = (int)$this->getSafeRequestValue('id');
        if (!$this->hasPermission(Permission::PERM_ZONE_SLAVE_ADD) || !$this->userMayAccessZone($zoneId)) {
            http_response_code(403);
            echo json_encode(['ready' => false, 'records' => 0]);
            return;
        }

        $records = $this->moduleServices()->dnsBackendProvider()->countZoneRecords($zoneId);
        echo json_encode(['ready' => $records > 0, 'records' => $records]);
    }

    /**
     * Whether the current user may read or convert the given zone: its owner
     * (directly or through a group), or an operator allowed to edit others'
     * zones. Prevents polling/probing record counts of unrelated zones.
     */
    private function userMayAccessZone(int $zoneId): bool
    {
        if ($zoneId <= 0) {
            return false;
        }
        if (
            $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER)
            || $this->hasPermission(Permission::PERM_ZONE_CONTENT_EDIT_OTHERS)
            || $this->hasPermission(Permission::PERM_ZONE_META_EDIT_OTHERS)
        ) {
            return true;
        }
        return $this->isZoneOwner($zoneId);
    }

    /**
     * In groups_only ownership mode the form has no usable owner controls when
     * the user has no assignable groups. Block that dead end up front with an
     * actionable message instead of letting every submission fail.
     */
    private function getOwnerOptionsBlocker(): ?string
    {
        return match ($this->moduleServices()->zoneCreateOwnershipResolver()->ownerOptionsBlocker((int)$this->getCurrentUserId())) {
            ZoneOwnershipResolution::NO_GROUPS_EXIST => _('Zone ownership mode is groups_only but no groups exist. Create a group before importing zones.'),
            ZoneOwnershipResolution::NOT_IN_ANY_GROUP => _('Zone ownership mode is groups_only but you are not a member of any group. Ask an administrator to add you to a group before importing zones.'),
            default => null,
        };
    }

    private function handleImport(): void
    {
        $rawDomain = trim((string)$this->httpRequest->getPostParam('domain', ''));
        $master = trim((string)$this->httpRequest->getPostParam('slave_master', ''));

        if ($rawDomain === '' || $master === '') {
            $this->setMessage('import', 'error', _('Zone name and primary server address are required.'));
            $this->showForm();
            return;
        }

        $created = $this->createZoneCreateService()->create(new ZoneCreateRequest(
            name: $rawDomain,
            type: 'SLAVE',
            ownerInput: $this->httpRequest->getPostParam('owner'),
            groupsInput: $this->httpRequest->getPostParam('groups'),
            callerUserId: (int)$this->getCurrentUserId(),
            slaveMaster: $master,
            importedFromPrimary: true
        ));
        if (!$created->success) {
            $this->setMessage('import', 'error', (string)$created->message);
            $this->showForm();
            return;
        }

        // Ask PowerDNS to pull the zone now instead of waiting for the refresh.
        $retrieved = $this->moduleServices()->domainManager()->retrieveZone((int)$created->zoneId);

        $this->showForm([
            'imported' => true,
            'imported_zone_id' => $created->zoneId,
            'imported_zone_name' => DnsIdnService::toUtf8($created->zoneName),
            'transfer_requested' => $retrieved,
        ]);
    }

    private function handleConvert(): void
    {
        $zoneId = (int)$this->httpRequest->getPostParam('zone_id', 0);
        if (!$this->userMayAccessZone($zoneId)) {
            $this->showError(_('Invalid zone.'));
            return;
        }

        // Refuse to convert before the transfer has populated the zone: an empty
        // conversion would discard the secondary and stop PowerDNS retrying AXFR.
        if ($this->moduleServices()->dnsBackendProvider()->countZoneRecords($zoneId) === 0) {
            $this->showError(_('This zone has no transferred records yet. Wait for the transfer to complete before converting it to a primary zone.'));
            return;
        }

        // changeZoneType() enforces the metadata-edit permission and ownership
        // and writes its own audit entry, so no extra gating is needed here.
        $converted = $this->moduleServices()->domainManager()->changeZoneType('NATIVE', $zoneId);
        if (!$converted->success) {
            $this->setMessage('import', 'error', (string)$converted->message);
            $this->showForm();
            return;
        }

        $this->setMessage('edit', 'success', _('Zone has been converted to a primary zone.'));
        $this->redirect('/zones/' . $zoneId . '/edit');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function showForm(array $extra = []): void
    {
        // The success/convert view doesn't render the form, so skip the user
        // and group lookups it would otherwise need.
        if (!empty($extra['imported'])) {
            $this->render('@secondary_zone_import/import.html', array_merge(['imported' => true], $extra));
            return;
        }

        $ownershipMode = $this->moduleServices()->zoneOwnershipModeService();
        $sessionUserId = $this->getUserContextService()->getLoggedInUserId();
        $isAdmin = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);
        $userGroupRepo = $this->moduleServices()->userGroupRepository();
        $allGroups = $isAdmin ? $userGroupRepo->findAll() : $userGroupRepo->findByUserId($sessionUserId);
        $memberCounts = $userGroupRepo->getMemberCountsByGroupIds(array_map(fn($g) => $g->getId(), $allGroups));

        $ownerInput = $this->httpRequest->getPostParam('owner');
        $groupsInput = $this->httpRequest->getPostParam('groups');

        $users = $this->moduleServices()->userRepository()->getUsersWithZoneCounts();
        $assignableOwners = $this->assignableOwners($users);
        $this->render('@secondary_zone_import/import.html', array_merge([
            'imported' => false,
            'domain_value' => htmlspecialchars((string)$this->httpRequest->getPostParam('domain', '')),
            'slave_master_value' => htmlspecialchars((string)$this->httpRequest->getPostParam('slave_master', '')),
            'users' => $users,
            'selectable_owners' => $assignableOwners,
            'session_user_id' => $sessionUserId,
            'owner_value' => $this->preservedOwnerChoice($assignableOwners, $ownerInput),
            'all_groups' => $allGroups,
            'group_member_counts' => $memberCounts,
            'selected_groups' => is_array($groupsInput) ? array_map('intval', $groupsInput) : [],
            'user_owner_allowed' => $ownershipMode->isUserOwnerAllowed(),
            'group_owner_allowed' => $ownershipMode->isGroupOwnerAllowed(),
        ], $extra));
    }
}
