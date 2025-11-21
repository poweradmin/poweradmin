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
 */

/**
 * Script that handles requests to add new user groups
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use InvalidArgumentException;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\GroupService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Logger\DbGroupLogger;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Symfony\Component\Validator\Constraints as Assert;

class AddGroupController extends BaseController
{
    private GroupService $groupService;
    private Request $request;
    private DbPermissionTemplateRepository $permissionTemplateRepository;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $groupRepository = new DbUserGroupRepository($this->db);
        $this->groupService = new GroupService($groupRepository);
        $this->request = new Request();
        $this->permissionTemplateRepository = new DbPermissionTemplateRepository($this->db, $this->config);
    }

    public function run(): void
    {
        // Only admin (überuser) can create groups
        $userContext = $this->getUserContextService();
        $userId = $userContext->getLoggedInUserId();
        if (!UserManager::isUserSuperuser($this->db, $userId)) {
            $this->setMessage('list_groups', 'error', _('You do not have permission to create groups.'));
            $this->redirect('/groups');
            return;
        }

        // Set the current page for navigation highlighting
        $this->requestData['page'] = 'add_group';

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

        $name = $this->request->getPostParam('name');
        $description = $this->request->getPostParam('description', '');
        $permTemplId = (int)$this->request->getPostParam('perm_templ');
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

            // Log group creation with actor and template details
            $ldapUse = $this->config->get('ldap', 'enabled');
            $currentUsers = UserManager::getUserDetailList($this->db, $ldapUse, $userId);
            $actorUsername = !empty($currentUsers) ? $currentUsers[0]['username'] : "ID: $userId";

            // Get permission template name for logging (no filter - need to find any template type)
            $permTemplates = UserManager::listPermissionTemplates($this->db);
            $templateName = 'Unknown';
            foreach ($permTemplates as $template) {
                if ($template['id'] == $permTemplId) {
                    $templateName = $template['name'];
                    break;
                }
            }

            $logMessage = sprintf(
                "Group created: '%s' (ID: %d) by %s with permission template '%s' (ID: %d)",
                $name,
                $group->getId(),
                $actorUsername,
                $templateName,
                $permTemplId
            );

            $logger = new DbGroupLogger($this->db);
            $logger->doLog($logMessage, $group->getId(), LOG_INFO);

            $this->setMessage('list_groups', 'success', _('Group has been created successfully.'));
            $this->redirect('/groups');
        } catch (InvalidArgumentException $e) {
            $this->setMessage('add_group', 'error', $e->getMessage());
            $this->renderAddGroupForm();
        }
    }

    private function renderAddGroupForm(): void
    {
        $permTemplates = UserManager::listPermissionTemplates($this->db, 'group');

        // Use minimal permission template as default (most secure)
        $defaultTemplateId = UserManager::getMinimalPermissionTemplateId($this->db) ?? '1';

        $this->render('add_group.html', [
            'name' => $this->request->getPostParam('name', ''),
            'description' => $this->request->getPostParam('description', ''),
            'perm_templ' => $this->request->getPostParam('perm_templ', (string)$defaultTemplateId),
            'perm_templates' => $permTemplates,
        ]);
    }

    private function validateInput(): bool
    {
        $constraints = [
            'name' => [
                new Assert\NotBlank(),
                new Assert\Length(['min' => 1, 'max' => 255])
            ],
            'perm_templ' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);
        $data = $this->request->getPostParams();

        if (!$this->doValidateRequest($data)) {
            $this->setMessage('add_group', 'error', _('Please fill in all required fields correctly.'));
            return false;
        }

        return true;
    }
}
