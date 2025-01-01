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
 * Script that handles requests to add new permission template
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;

class AddPermTemplController extends BaseController
{
    private DbPermissionTemplateRepository $permissionTemplate;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->permissionTemplate = new DbPermissionTemplateRepository($this->db);
    }

    public function run(): void
    {
        $this->checkPermission('templ_perm_add', _("You do not have the permission to add permission templates."));

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

        $this->permissionTemplate->addPermissionTemplate($this->getRequest());
        $this->setMessage('list_perm_templ', 'success', _('The permission template has been added successfully.'));
        $this->redirect('index.php', ['page' => 'list_perm_templ']);
    }

    private function showForm(): void
    {
        $this->render('add_perm_templ.html', [
            'perms_avail' => $this->permissionTemplate->getPermissionsByTemplateId()
        ]);
    }

    private function validateSubmitRequest(): bool
    {
        $this->setRequestRules([
            'required' => ['templ_name'],
            'lengthMax' => [
                ['templ_name', 128],
                ['templ_descr', 1024],
            ],
            'array' => ['perm_id'],
        ]);

        return $this->doValidateRequest();
    }
}
