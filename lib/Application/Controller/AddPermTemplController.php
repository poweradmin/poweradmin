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

/**
 * Script that handles requests to add new permission template
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\PermissionTemplateWriteService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\PermissionTemplateContentGuard;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Poweradmin\Domain\Enum\PermissionTemplateType;

class AddPermTemplController extends BaseController
{
    private DbPermissionTemplateRepository $permissionTemplate;
    private PermissionTemplateWriteService $permissionTemplateWriteService;
    private LegacyLogger $auditLogger;
    private UserContextService $userContextService;
    private IpAddressRetriever $ipAddressRetriever;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->permissionTemplate = $this->createPermissionTemplateRepository();
        $this->permissionTemplateWriteService = $this->createPermissionTemplateWriteService();
        $this->auditLogger = new LegacyLogger($this->db);
        $this->userContextService = new UserContextService();
        $this->ipAddressRetriever = new IpAddressRetriever($_SERVER);
    }

    public function run(): void
    {
        $this->checkPermission('templ_perm_add', _("You do not have the permission to add permission templates."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('add_perm_templ');
        $this->setPageTitle(_('Add permission template'));

        if ($this->isPost()) {
            $this->handleFormSubmission();
        } else {
            $this->showForm();
        }
    }

    private function handleFormSubmission(): void
    {
        $this->validateCsrfToken();

        if (!$this->validateSubmitRequest()) {
            $this->showFirstValidationError();
            return;
        }

        $result = $this->permissionTemplateWriteService->create($this->callerId(), $this->getRequest());
        if (!$result['success']) {
            $this->setMessage('list_perm_templ', 'error', $this->translateWriteError($result['message']));
            $this->showForm();
            return;
        }

        $this->auditLogger->logInfo(sprintf(
            'client_ip:%s user:%s operation:add_perm_template name:%s',
            $this->ipAddressRetriever->getClientIp(),
            $this->userContextService->getLoggedInUsername(),
            $this->getSafeRequestValue('templ_name')
        ));

        $this->setMessage('list_perm_templ', 'success', _('The permission template has been added successfully.'));
        $this->redirect('/permissions/templates');
    }

    private function showForm(): void
    {
        $this->render('add_perm_templ.html', [
            'perms_avail' => PermissionTemplateContentGuard::filterOfferedPermissions(
                $this->permissionTemplate->getPermissionsByTemplateId(),
                $this->permissionTemplateWriteService->callerMaySetSuperuser($this->callerId())
            ),
            'show_user_access_templates' => $this->config->get('permissions', 'show_user_access_templates', true),
            'show_group_access_templates' => $this->config->get('permissions', 'show_group_access_templates', true),
        ]);
    }

    private function validateSubmitRequest(): bool
    {
        $this->setRequestRules([
            'required' => ['templ_name', 'template_type'],
            'lengthMax' => [
                ['templ_name', 128],
                ['templ_descr', 1024],
            ],
            'array' => ['perm_id'],
            'in' => [
                ['template_type', PermissionTemplateType::values()]
            ],
        ]);

        return $this->doValidateRequest();
    }

    private function callerId(): int
    {
        return (int)$this->userContextService->getLoggedInUserId();
    }

    private function translateWriteError(string $message): string
    {
        return match ($message) {
            PermissionTemplateContentGuard::CONTENT_SUPERUSER_DENIED =>
                _('Granting administrator rights in a permission template requires administrator rights.'),
            default => _('The permission template could not be added.'),
        };
    }
}
