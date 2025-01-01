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
 * Script that handles deletion of zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;

class DeletePermTemplController extends BaseController
{
    private DbPermissionTemplateRepository $permissionTemplate;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->permissionTemplate = new DbPermissionTemplateRepository($this->db);
    }

    public function run(): void
    {
        $this->checkPermission('user_edit_templ_perm', _("You do not have the permission to delete permission templates."));

        if (isset($_GET['confirm'])) {
            $this->handleFormSubmission();
        } else {
            $this->showForm();
        }
    }

    private function handleFormSubmission(): void
    {
        if (!$this->validateSubmitRequest()) {
            $this->showFirstValidationError();
            return;
        }

        $id = $this->getSafeRequestValue('id');
        if (UserManager::delete_perm_templ($this->db, $id)) {
            $this->setMessage('list_perm_templ', 'success', _('The permission template has been deleted successfully.'));
            $this->redirect('index.php', ['page'=> 'list_perm_templ']);
        }

        $this->render('list_perm_templ.html', [
            'permission_templates' => $this->permissionTemplate->listPermissionTemplates(),
        ]);
    }

    private function showForm(): void
    {
        $id = $this->getSafeRequestValue('id');
        $templ_details = $this->permissionTemplate->getPermissionTemplateDetails($id);

        $this->render('delete_perm_templ.html', [
            'perm_templ_id' => $id,
            'templ_name' => $templ_details['name'],
        ]);
    }

    private function validateSubmitRequest(): bool
    {
        $this->setRequestRules([
            'required' => ['id'],
            'integer' => ['id'],
        ]);

        return $this->doValidateRequest();
    }
}
