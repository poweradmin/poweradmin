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
use Poweradmin\Application\Service\User\PermissionTemplateWriteService;
use Poweradmin\Domain\Service\User\LastSuperAdminGuard;
use Symfony\Component\Validator\Constraints as Assert;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\PermissionTemplateContentGuard;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Domain\Enum\PermissionTemplateType;

/**
 * Handles the edit-permission-template form: updates the template's details and permission set.
 */
class EditPermTemplController extends BaseController
{
    private ?PermissionTemplateRepositoryInterface $permissionTemplate = null;
    private ?PermissionTemplateWriteService $permissionTemplateWriteService = null;

    private function permissionTemplate(): PermissionTemplateRepositoryInterface
    {
        return $this->permissionTemplate ??= $this->services()->permissionTemplateRepository();
    }

    private function permissionTemplateWriteService(): PermissionTemplateWriteService
    {
        return $this->permissionTemplateWriteService ??= $this->services()->permissionTemplateWriteService();
    }

    public function run(): void
    {
        $this->checkPermission(Permission::PERM_TEMPL_PERM_EDIT, _("You do not have the permission to edit permission templates."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('edit_perm_templ');
        $this->setPageTitle(_('Edit Permission Template'));

        if (!$this->validateRequest()) {
            $this->showFirstValidationError();
            return;
        }

        $templateId = (int)$this->getSafeRequestValue('id');
        $guardError = PermissionTemplateContentGuard::apply(
            $this->services()->userRepository(),
            $this->callerId(),
            $templateId,
            null
        );
        if ($guardError !== null) {
            $this->showError($this->translateWriteError($guardError));
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
        if (!$this->validateSubmitRequest()) {
            $this->showFirstValidationError();
            return;
        }

        // Ensure perm_id is always a list so an all-unchecked form clears the
        // permission list rather than leaving it untouched or iterating a scalar.
        $request = $this->getRequest();
        $request['perm_id'] = is_array($request['perm_id'] ?? null) ? $request['perm_id'] : [];

        // The route id is the only authoritative one; a posted templ_id would let
        // this write a template the URL never named.
        $templateId = (int)$this->getSafeRequestValue('id');

        // A form opened before another editor saved would put back what they changed
        if (!hash_equals($this->formState($templateId), (string)$this->getSafeRequestValue('form_state'))) {
            $this->setMessage('edit_perm_templ', 'error', _('This permission template was changed by someone else after you opened it. Review the current values and save again.'));
            $this->showForm();
            return;
        }

        $permissionsBefore = $this->permissionTemplate()->getPermissionsByTemplateId($templateId, true);
        $result = $this->permissionTemplateWriteService()->update($this->callerId(), $templateId, $request);
        if (!$result['success']) {
            $this->setMessage('edit_perm_templ', 'error', $this->translateWriteError($result['message']));
            $this->showForm();
            return;
        }

        $this->services()->auditService()->logPermTemplateEdit(
            $templateId,
            $this->getSafeRequestValue('templ_name'),
            $permissionsBefore,
            $this->permissionTemplate()->getPermissionsByTemplateId($templateId, true)
        );

        $this->setMessage('list_perm_templ', 'success', _('The permission template has been updated successfully.'));
        $this->redirect('/permissions/templates');
    }

    private function showForm(): void
    {
        $id = $this->getSafeRequestValue('id');
        $this->render('edit_perm_templ.html', [
            'id' => $id,
            'form_state' => $this->formState((int)$id),
            'templ' => $this->permissionTemplate()->getPermissionTemplateDetails((int)$id),
            'perms_templ' => $this->permissionTemplate()->getPermissionsByTemplateId((int)$id),
            'perms_avail' => PermissionTemplateContentGuard::filterOfferedPermissions(
                $this->permissionTemplate()->getPermissionsByTemplateId(),
                $this->permissionTemplateWriteService()->callerMaySetSuperuser($this->callerId())
            ),
            'show_user_access_templates' => $this->config->get('permissions', 'show_user_access_templates', true),
            'show_group_access_templates' => $this->config->get('permissions', 'show_group_access_templates', true),
        ]);
    }

    private function formState(int $templateId): string
    {
        return self::stateOf(
            $this->permissionTemplate()->getPermissionTemplateDetails($templateId) ?: [],
            $this->permissionTemplate()->getPermissionsByTemplateId($templateId)
        );
    }

    /**
     * A stamp of the stored values this form writes, so a save can tell it started from them.
     *
     * @param array<string, mixed> $templ Row from getPermissionTemplateDetails()
     * @param list<array<string, mixed>> $perms Rows from getPermissionsByTemplateId()
     */
    public static function stateOf(array $templ, array $perms): string
    {
        $permIds = array_map(fn(array $perm) => (int)$perm['id'], $perms);
        sort($permIds);

        return hash('sha256', (string)json_encode([$templ['name'] ?? '', $templ['descr'] ?? '', $templ['template_type'] ?? '', $permIds], JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function validateRequest(): bool
    {
        $this->setValidationConstraints([
            'id' => new Assert\Required([
                new Assert\NotBlank(message: sprintf(_('The %s field is required.'), 'id')),
                new Assert\Type('numeric', message: sprintf(_('The %s field must be a number.'), 'id')),
            ]),
        ]);

        return $this->doValidateRequest();
    }

    private function validateSubmitRequest(): bool
    {
        $this->setValidationConstraints([
            'templ_name' => new Assert\Required([new Assert\NotBlank(message: sprintf(_('The %s field is required.'), 'templ_name'))]),
            'template_type' => new Assert\Required([
                new Assert\NotBlank(message: sprintf(_('The %s field is required.'), 'template_type')),
                new Assert\Choice(choices: PermissionTemplateType::values(), message: sprintf(_('The %s field has an invalid value.'), 'template_type')),
            ]),
        ]);

        return $this->doValidateRequest();
    }

    private function callerId(): int
    {
        return (int)$this->getUserContextService()->getLoggedInUserId();
    }

    private function translateWriteError(string $message): string
    {
        return match ($message) {
            PermissionTemplateContentGuard::CONTENT_SUPERUSER_DENIED =>
                _('Granting administrator rights in a permission template requires administrator rights.'),
            PermissionTemplateContentGuard::EDIT_SUPERUSER_DENIED =>
                _('Editing a permission template with administrator rights requires administrator rights.'),
            PermissionTemplateContentGuard::MFA_ENFORCEMENT_DENIED =>
                _('Removing the enforced MFA permission from a permission template held by an administrator requires administrator rights.'),
            LastSuperAdminGuard::MESSAGE => _('Cannot remove super admin from the last remaining super admin user.'),
            default => _('The permission template could not be updated.'),
        };
    }
}
