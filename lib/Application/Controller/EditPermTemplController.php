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
 * Script that handles editing of permission templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;

class EditPermTemplController extends BaseController
{
    private DbPermissionTemplateRepository $permissionTemplate;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->permissionTemplate = new DbPermissionTemplateRepository($this->db);
    }

    public function run(): void
    {
        $this->checkPermission('templ_perm_edit', _("You do not have the permission to edit permission templates."));

        if (!$this->validateRequest()) {
            $this->showFirstValidationError();
            return;
        }

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

        $this->permissionTemplate->updatePermissionTemplateDetails($this->getRequest());
        $this->setMessage('list_perm_templ', 'success', _('The permission template has been updated successfully.'));
        $this->redirect('index.php', ['page'=> 'list_perm_templ']);
    }

    private function showForm(): void
    {
        $id = $this->getSafeRequestValue('id');
        $this->render('edit_perm_templ.html', [
            'id' => $id,
            'templ' => $this->permissionTemplate->getPermissionTemplateDetails($id),
            'perms_templ' => $this->permissionTemplate->getPermissionsByTemplateId($id),
            'perms_avail' => $this->permissionTemplate->getPermissionsByTemplateId(),
        ]);
    }

    private function validateRequest(): bool
    {
        $this->setRequestRules([
            'required' => ['id'],
            'integer' => ['id'],
        ]);

        return $this->doValidateRequest();
    }

    private function validateSubmitRequest(): bool
    {
        $this->setRequestRules([
            'required' => ['templ_name'],
        ]);

        return $this->doValidateRequest();
    }
}
