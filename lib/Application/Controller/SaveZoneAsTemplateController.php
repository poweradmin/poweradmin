<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
 *
 */

/**
 * Script that handles saving a zone as a template
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

class SaveZoneAsTemplateController extends BaseController
{
    private UserContextService $userContextService;
    private ZoneRepositoryInterface $zoneRepository;
    private PermissionService $permissionService;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->userContextService = new UserContextService();
        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());

        $userRepository = new DbUserRepository($this->db, $this->getConfig());
        $this->permissionService = new PermissionService($userRepository);
    }

    public function run(): void
    {
        // Set the current page for navigation highlighting
        $this->requestData['page'] = 'edit';

        $zone_id = $this->getSafeRequestValue('id');
        if (!$zone_id || !is_numeric($zone_id)) {
            $this->showError(_('Invalid or unexpected input given.'));
            return;
        }
        $zone_id = (int)$zone_id;

        // Check permissions
        $userId = $this->userContextService->getLoggedInUserId();
        $perm_zone_templ_add = $this->permissionService->canAddZoneTemplates($userId);
        $perm_is_godlike = $this->permissionService->isAdmin($userId);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        if (!($perm_zone_templ_add || $perm_is_godlike)) {
            $this->showError(_('You do not have permission to create zone templates.'));
            return;
        }

        // Only zone owners or admins can save their zones as templates
        $perm_view = $this->permissionService->getViewPermissionLevel($userId);
        if ($perm_view !== "all" && !$user_is_zone_owner) {
            $this->showError(_('You do not have permission to access this zone.'));
            return;
        }

        // Get zone information
        $zone_name = $this->zoneRepository->getDomainNameById($zone_id);
        if ($zone_name === null) {
            $this->showError(_('Zone not found.'));
            return;
        }

        $domain_type = $this->zoneRepository->getDomainType($zone_id);

        // Handle form submission
        if ($this->isPost() && isset($_POST['save_as'])) {
            $this->validateCsrfToken();
            $this->saveAsTemplate($zone_id, $zone_name);
        }

        // Render the form
        $this->render('save-zone-template.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'domain_type' => $domain_type,
            'templ_name' => $_POST['templ_name'] ?? '',
            'templ_descr' => $_POST['templ_descr'] ?? '',
        ]);
    }

    private function saveAsTemplate(int $zone_id, string $zone_name): void
    {
        $template_name = htmlspecialchars($_POST['templ_name']) ?? '';
        $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig());

        if ($zoneTemplate->zoneTemplNameExists($template_name)) {
            $this->setMessage('save_zone_template', 'error', _('Zone template with this name already exists, please choose another one.'));
            return;
        }

        if ($template_name == '') {
            $this->setMessage('save_zone_template', 'error', _("Template name can't be an empty string."));
            return;
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $records = $dnsRecord->getRecordsFromDomainId($this->config->get('database', 'type', 'mysql'), $zone_id);

        $description = htmlspecialchars($_POST['templ_descr']) ?? '';

        $options = [
            'NS1' => $this->config->get('dns', 'ns1', '') ?? '',
            'HOSTMASTER' => $this->config->get('dns', 'hostmaster', '') ?? '',
        ];

        $zoneTemplate->addZoneTemplSaveAs(
            $template_name,
            $description,
            $this->userContextService->getLoggedInUserId(),
            $records,
            $options,
            $zone_name
        );

        $this->setMessage('save_zone_template', 'success', _('Zone template has been created successfully.'));

        // Redirect to template list after successful save
        $this->redirect("/zones/templates");
    }
}
