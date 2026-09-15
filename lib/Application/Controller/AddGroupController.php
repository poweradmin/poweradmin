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

use InvalidArgumentException;
use Poweradmin\Application\Service\GroupService;
use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the add-group form at /groups/add: validates the name and group template, then creates the group.
 */
class AddGroupController extends BaseController
{
    private GroupService $groupService;
    private DbPermissionTemplateRepository $permissionTemplateRepository;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = $this->createUserGroupRepository();
        $this->groupService = new GroupService($groupRepository);
        $this->permissionTemplateRepository = $this->createPermissionTemplateRepository();
    }

    public function run(): void
    {
        if (!$this->config->get('permissions', 'show_group_access_templates', true)) {
            $this->showError(_('Group management is not enabled.'));
            return;
        }

        // Only admin (überuser) can create groups
        $userContext = $this->getUserContextService();
        $userId = $userContext->getLoggedInUserId();
        if (!$this->createPermissionService()->canManageGroups($userId)) {
            $this->setMessage('list_groups', 'error', _('You do not have permission to create groups.'));
            $this->redirect('/groups');
            return;
        }

        // Set the current page for navigation highlighting
        $this->setCurrentPage('add_group');
        $this->setPageTitle(_('Add group'));

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->addGroup();
        } else {
            $this->renderAddGroupForm();
        }
    }

    private function addGroup(): void
    {
        if (!$this->validateInput()) {
            $this->renderAddGroupForm();
            return;
        }

        $name = $this->httpRequest->getPostParam('name');
        $description = $this->httpRequest->getPostParam('description', '');
        $permTemplId = (int)$this->httpRequest->getPostParam('perm_templ');
        $userContext = $this->getUserContextService();
        $userId = $userContext->getLoggedInUserId();

        // Validate that the template is a group template
        if (!$this->permissionTemplateRepository->validateTemplateType($permTemplId, 'group')) {
            $this->setMessage('add_group', 'error', _('Invalid permission template: must be a group template'));
            $this->renderAddGroupForm();
            return;
        }

        try {
            $group = $this->groupService->createGroup($name, $permTemplId, $description, $userId);

            // Log group creation with template details
            $permTemplates = $this->permissionTemplateRepository->listPermissionTemplates();
            $templateName = 'Unknown';
            foreach ($permTemplates as $template) {
                if ($template['id'] == $permTemplId) {
                    $templateName = $template['name'];
                    break;
                }
            }

            $this->createAuditService()->logGroupCreate($group->getId(), (string)$name, (string)$templateName, $permTemplId);

            $this->setMessage('list_groups', 'success', _('Group has been created successfully.'));
            $this->redirect('/groups');
        } catch (InvalidArgumentException $e) {
            $this->setMessage('add_group', 'error', $e->getMessage());
            $this->renderAddGroupForm();
        }
    }

    private function renderAddGroupForm(): void
    {
        $permTemplates = $this->permissionTemplateRepository->listPermissionTemplates('group');

        // Use minimal permission template as default (most secure); preselect
        // nothing rather than falling back to template id 1 (Administrator).
        $defaultTemplateId = $this->permissionTemplateRepository->getMinimalPermissionTemplateId('group') ?? '';

        $this->render('add_group.html', [
            'name' => $this->httpRequest->getPostParam('name', ''),
            'description' => $this->httpRequest->getPostParam('description', ''),
            'perm_templ' => $this->httpRequest->getPostParam('perm_templ', (string)$defaultTemplateId),
            'perm_templates' => $permTemplates,
        ]);
    }

    private function validateInput(): bool
    {
        $constraints = [
            'name' => [
                new Assert\NotBlank(),
                new Assert\Length(min: 1, max: 255)
            ],
            'perm_templ' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);
        $data = $this->httpRequest->getPostParams();

        if (!$this->doValidateRequest($data)) {
            $this->setMessage('add_group', 'error', _('Please fill in all required fields correctly.'));
            return false;
        }

        return true;
    }
}
