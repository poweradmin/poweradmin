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

use Poweradmin\Application\Http\Request;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

/**
 * Imports a zone from a live primary by creating a secondary, triggering an
 * AXFR pull, and offering a one-click conversion to a primary zone once the
 * records have transferred. API-backend only (see the module class).
 */
class SecondaryZoneImportController extends BaseController
{
    private LegacyLogger $auditLogger;
    private IPAddressValidator $ipAddressValidator;
    private UserContextService $userContextService;
    private IpAddressRetriever $ipAddressRetriever;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->auditLogger = new LegacyLogger($this->db);
        $this->ipAddressValidator = new IPAddressValidator();
        $this->userContextService = new UserContextService();
        $this->ipAddressRetriever = new IpAddressRetriever($_SERVER);
        $this->request = new Request();
    }

    public function run(): void
    {
        $this->checkPermission('zone_slave_add', _('You do not have the permission to import a secondary zone.'));
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

        if ($this->request->getPostParam('action') === 'convert') {
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
        if (!UserManager::verifyPermission($this->db, 'zone_slave_add') || !$this->userMayAccessZone($zoneId)) {
            http_response_code(403);
            echo json_encode(['ready' => false, 'records' => 0]);
            return;
        }

        $records = $this->createDnsBackendProvider()->countZoneRecords($zoneId);
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
        if (UserManager::verifyPermission($this->db, 'user_is_ueberuser')
            || UserManager::verifyPermission($this->db, 'zone_content_edit_others')
            || UserManager::verifyPermission($this->db, 'zone_meta_edit_others')) {
            return true;
        }
        return UserManager::verifyUserIsOwnerZoneId($this->db, $zoneId);
    }

    /**
     * In groups_only ownership mode the form has no usable owner controls when
     * the user has no assignable groups. Block that dead end up front with an
     * actionable message instead of letting every submission fail.
     */
    private function getOwnerOptionsBlocker(): ?string
    {
        $ownershipMode = new ZoneOwnershipModeService($this->config);
        if ($ownershipMode->isUserOwnerAllowed()) {
            return null;
        }
        $userGroupRepo = new DbUserGroupRepository($this->db);
        if (UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
            if (empty($userGroupRepo->findAll())) {
                return _('Zone ownership mode is groups_only but no groups exist. Create a group before importing zones.');
            }
            return null;
        }
        if (empty($userGroupRepo->findByUserId($this->userContextService->getLoggedInUserId()))) {
            return _('Zone ownership mode is groups_only but you are not a member of any group. Ask an administrator to add you to a group before importing zones.');
        }
        return null;
    }

    private function handleImport(): void
    {
        $rawDomain = trim((string)$this->request->getPostParam('domain', ''));
        $master = trim((string)$this->request->getPostParam('slave_master', ''));

        if ($rawDomain === '' || $master === '') {
            $this->setMessage('import', 'error', _('Zone name and primary server address are required.'));
            $this->showForm();
            return;
        }

        $zone = DnsIdnService::toPunycode($rawDomain);
        $ownershipMode = new ZoneOwnershipModeService($this->config);
        [$owner, $groups, $ownerError] = $this->resolveOwnership($ownershipMode);
        if ($ownerError !== null) {
            $this->setMessage('import', 'error', $ownerError);
            $this->showForm();
            return;
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $hostnameValidator = new HostnameValidator($this->config);

        if (!$hostnameValidator->isValid($zone)) {
            $this->setMessage('import', 'error', _('Invalid hostname.'));
            $this->showForm();
            return;
        }
        $thirdLevelCheck = $this->config->get('dns', 'third_level_check', false);
        if ($thirdLevelCheck && DnsRecord::getDomainLevel($zone) > 2 && $dnsRecord->domainExists(DnsRecord::getSecondLevelDomain($zone))) {
            $this->setMessage('import', 'error', _('There is already a zone with this name.'));
            $this->showForm();
            return;
        }
        if ($dnsRecord->domainExists($zone) || $dnsRecord->hasNonDelegationRecords($zone)) {
            $this->setMessage('import', 'error', _('There is already a zone with this name.'));
            $this->showForm();
            return;
        }
        if (($overlapError = $this->getZoneOverlapError($zone)) !== null) {
            $this->setMessage('import', 'error', $overlapError);
            $this->showForm();
            return;
        }
        if (!$this->ipAddressValidator->areMultipleValidIPs($master)) {
            $this->setMessage('import', 'error', _('This is not a valid IPv4 or IPv6 address.'));
            $this->showForm();
            return;
        }

        if (!$dnsRecord->addDomain($this->db, $zone, $owner, 'SLAVE', $master, 'none', $groups)) {
            $this->setMessage('import', 'error', _('Failed to create the secondary zone.'));
            $this->showForm();
            return;
        }

        $zoneId = $dnsRecord->getZoneIdFromName($zone);
        $this->auditLogger->logInfo(sprintf(
            'client_ip:%s user:%s operation:import_secondary_zone zone:%s zone_master:%s',
            $this->ipAddressRetriever->getClientIp(),
            $this->userContextService->getLoggedInUsername(),
            $zone,
            $master
        ), $zoneId);

        // Ask PowerDNS to pull the zone now instead of waiting for the refresh.
        $retrieved = $zoneId ? $dnsRecord->retrieveZone($zoneId) : false;

        $this->showForm([
            'imported' => true,
            'imported_zone_id' => $zoneId,
            'imported_zone_name' => DnsIdnService::toUtf8($zone),
            'transfer_requested' => $retrieved,
        ]);
    }

    private function handleConvert(): void
    {
        $zoneId = (int)$this->request->getPostParam('zone_id', 0);
        if (!$this->userMayAccessZone($zoneId)) {
            $this->showError(_('Invalid zone.'));
            return;
        }

        // Refuse to convert before the transfer has populated the zone: an empty
        // conversion would discard the secondary and stop PowerDNS retrying AXFR.
        if ($this->createDnsBackendProvider()->countZoneRecords($zoneId) === 0) {
            $this->showError(_('This zone has no transferred records yet. Wait for the transfer to complete before converting it to a primary zone.'));
            return;
        }

        // changeZoneType() enforces the metadata-edit permission and ownership
        // and writes its own audit entry, so no extra gating is needed here.
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        if (!$dnsRecord->changeZoneType('NATIVE', $zoneId)) {
            $this->showForm();
            return;
        }

        $this->setMessage('edit', 'success', _('Zone has been converted to a primary zone.'));
        $this->redirect('/zones/' . $zoneId . '/edit');
    }

    /**
     * Determine the owner and group assignment for the imported zone, mirroring
     * the add-secondary-zone form: a user owner (default: the current user) plus
     * any selected groups. Assigning to another user needs elevated permission.
     *
     * @return array{0: ?int, 1: int[], 2: ?string} [owner, groupIds, errorMessage]
     */
    private function resolveOwnership(ZoneOwnershipModeService $ownershipMode): array
    {
        $callerId = $this->userContextService->getLoggedInUserId();
        $ownerInput = $this->request->getPostParam('owner');
        $owner = $ownershipMode->isUserOwnerAllowed() && !empty($ownerInput) ? (int)$ownerInput : null;

        if (
            $owner !== null && $owner !== $callerId
            && !UserManager::verifyPermission($this->db, 'user_is_ueberuser')
            && !UserManager::verifyPermission($this->db, 'zone_content_edit_others')
        ) {
            return [null, [], _('You do not have permission to create zones for other users.')];
        }

        $groups = [];
        $groupsInput = $this->request->getPostParam('groups');
        if ($ownershipMode->isGroupOwnerAllowed() && is_array($groupsInput)) {
            $requested = array_values(array_unique(array_map('intval', $groupsInput)));
            $userGroupRepo = new DbUserGroupRepository($this->db);
            $existing = $userGroupRepo->findExistingIds($requested);
            $unknown = array_values(array_diff($requested, $existing));
            if (!empty($unknown)) {
                return [null, [], sprintf(_('Unknown group ID(s): %s'), implode(',', $unknown))];
            }
            if (!UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
                $allowedIds = array_map(fn($g) => $g->getId(), $userGroupRepo->findByUserId($callerId));
                $disallowed = array_values(array_diff($existing, $allowedIds));
                if (!empty($disallowed)) {
                    return [null, [], sprintf(_('You can only assign groups you are a member of (disallowed: %s)'), implode(',', $disallowed))];
                }
            }
            $groups = $existing;
        }

        if ($owner === null && empty($groups)) {
            return [null, [], _('At least one user or group must be selected as owner.')];
        }

        return [$owner, $groups, null];
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

        $ownershipMode = new ZoneOwnershipModeService($this->config);
        $sessionUserId = $this->userContextService->getLoggedInUserId();
        $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $userGroupRepo = new DbUserGroupRepository($this->db);
        $allGroups = $isAdmin ? $userGroupRepo->findAll() : $userGroupRepo->findByUserId($sessionUserId);
        $memberCounts = $userGroupRepo->getMemberCountsByGroupIds(array_map(fn($g) => $g->getId(), $allGroups));

        $ownerInput = $this->request->getPostParam('owner');
        $groupsInput = $this->request->getPostParam('groups');

        $this->render('@secondary_zone_import/import.html', array_merge([
            'imported' => false,
            'domain_value' => htmlspecialchars((string)$this->request->getPostParam('domain', '')),
            'slave_master_value' => htmlspecialchars((string)$this->request->getPostParam('slave_master', '')),
            'users' => UserManager::showUsers($this->db),
            'session_user_id' => $sessionUserId,
            'perm_view_others' => UserManager::verifyPermission($this->db, 'user_view_others'),
            'owner_value' => $ownerInput !== null ? $ownerInput : $sessionUserId,
            'all_groups' => $allGroups,
            'group_member_counts' => $memberCounts,
            'selected_groups' => is_array($groupsInput) ? array_map('intval', $groupsInput) : [],
            'user_owner_allowed' => $ownershipMode->isUserOwnerAllowed(),
            'group_owner_allowed' => $ownershipMode->isGroupOwnerAllowed(),
        ], $extra));
    }
}
