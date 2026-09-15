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

use Poweradmin\Application\Http\Request;
use Poweradmin\BaseController;
use Symfony\Component\Validator\Constraints as Assert;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;

/**
 * Handles the delete-permission-template confirmation page and deletes the template on confirmed POST.
 */
class DeletePermTemplController extends BaseController
{
    private DbPermissionTemplateRepository $permissionTemplate;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->request = new Request();

        $this->permissionTemplate = $this->createPermissionTemplateRepository();
    }

    public function run(): void
    {
        $this->checkPermission('user_edit_templ_perm', _("You do not have the permission to delete permission templates."));

        if ($this->request->getPostParam('confirm') !== null) {
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

        $id = (int)$this->getSafeRequestValue('id');
        $templDetails = $this->permissionTemplate->getPermissionTemplateDetails($id);

        if ($this->permissionTemplate->deletePermissionTemplate($id)) {
            $this->createAuditService()->logPermTemplateDelete($id, (string)($templDetails['name'] ?? 'unknown'));

            $this->setMessage('list_perm_templ', 'success', _('The permission template has been deleted successfully.'));
        } else {
            $this->setMessage('list_perm_templ', 'error', _('The permission template could not be deleted.'));
        }

        $this->redirect('/permissions/templates');
    }

    private function showForm(): void
    {
        $id = $this->getSafeRequestValue('id');
        $templ_details = $this->permissionTemplate->getPermissionTemplateDetails((int)$id);

        $this->render('delete_perm_templ.html', [
            'perm_templ_id' => $id,
            'templ_name' => $templ_details['name'],
        ]);
    }

    private function validateSubmitRequest(): bool
    {
        $this->setValidationConstraints([
            'id' => [new Assert\NotBlank(), new Assert\Type('numeric')],
        ]);

        return $this->doValidateRequest();
    }
}
