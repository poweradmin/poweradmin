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

namespace Poweradmin\Application\Controller\User;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\PermissionTemplateMessages;
use Symfony\Component\Validator\Constraints as Assert;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;

/**
 * Handles the delete-permission-template confirmation page and deletes the template on confirmed POST.
 */
class DeletePermTemplController extends BaseController
{
    private DbPermissionTemplateRepository $permissionTemplate;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->permissionTemplate = $this->services()->permissionTemplateRepository();
    }

    public function run(): void
    {
        $this->checkPermission(Permission::PERM_USER_EDIT_TEMPL_PERM, _("You do not have the permission to delete permission templates."));

        if ($this->httpRequest->getPostParam('confirm') !== null) {
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

        $result = $this->permissionTemplate->deletePermissionTemplate($id);
        if ($result->isDeleted()) {
            $this->services()->auditService()->logPermTemplateDelete($id, (string)($templDetails['name'] ?? 'unknown'));
        }
        $this->setMessage('list_perm_templ', $result->isDeleted() ? 'success' : 'error', PermissionTemplateMessages::forDeleteResult($result));

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
            'id' => new Assert\Required([
                new Assert\NotBlank(message: sprintf(_('The %s field is required.'), 'id')),
                new Assert\Type('numeric', message: sprintf(_('The %s field must be a number.'), 'id')),
            ]),
        ]);

        return $this->doValidateRequest();
    }
}
