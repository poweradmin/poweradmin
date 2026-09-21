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

use Poweradmin\Application\Service\PasswordChangeService;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Symfony\Component\Validator\Constraints as Assert;
use Poweradmin\Domain\Enum\AuthMethod;

/**
 * Handles the change-password form for the logged-in user; LDAP, OIDC and SAML users are refused.
 */
class ChangePasswordController extends BaseController
{
    private AuthenticationService $authService;
    private PasswordPolicyService $policyService;
    private PasswordChangeService $passwordService;
    private UserContextService $userContextService;

    public function __construct(array $request, ?ControllerEnvironment $environment = null)
    {
        parent::__construct($request, true, $environment);
        $this->authService = $this->services()->authenticationService();
        $this->policyService = new PasswordPolicyService($this->config);

        $userAuthService = UserAuthenticationService::fromConfig($this->config);
        $userRepository = $this->services()->userRepository();
        $this->userContextService = new UserContextService();
        $this->passwordService = new PasswordChangeService($userRepository, $userAuthService, $this->userContextService);
    }

    public function run(): void
    {
        // Check for external authentication methods that don't allow password changes
        $authUsed = $_SESSION[SessionKeys::AUTH_USED] ?? null;

        // Block external authentication users
        if (AuthMethod::fromDb($authUsed)->isExternal()) {
            $message = match ($authUsed) {
                'ldap' => _('LDAP users cannot change their password here. Please contact your administrator.'),
                'oidc', 'saml' => _('Users authenticated via Single Sign-On cannot change their password here. Please contact your administrator or change your password through your identity provider.'),
                default => _('External authentication users cannot change their password here. Please contact your administrator.')
            };
            $this->checkCondition(true, $message);
        }

        // Set the current page for navigation highlighting
        $this->setCurrentPage('change_password');
        $this->setPageTitle(_('Change password'));

        $policyConfig = $this->policyService->getPolicyConfig();

        if (!$this->isPost()) {
            $this->renderChangePasswordForm($policyConfig);
            return;
        }

        // Make sure we have the latest POST data
        $this->httpRequest->refresh();

        if (!$this->validateInput()) {
            $this->renderChangePasswordForm($policyConfig);
            return;
        }

        if (!$this->validatePasswordPolicy()) {
            $this->renderChangePasswordForm($policyConfig);
            return;
        }

        if (!$this->processPasswordChange()) {
            $this->renderChangePasswordForm($policyConfig);
        }
    }

    private function renderChangePasswordForm(array $policyConfig): void
    {
        $this->render('change_password.html', [
            'password_policy' => $policyConfig,
        ]);
    }

    private function validateInput(): bool
    {
        $constraints = [
            'old_password' => [
                new Assert\NotBlank()
            ],
            'new_password' => [
                new Assert\NotBlank()
            ],
            'new_password2' => [
                new Assert\NotBlank(),
                new Assert\EqualTo([
                    'value' => $this->httpRequest->getPostParam('new_password'),
                    'message' => 'Repeat password must match the new password.'
                ])
            ]
        ];

        $this->setValidationConstraints($constraints);
        $data = $this->httpRequest->getPostParams();

        if (!$this->doValidateRequest($data)) {
            $this->setMessage('change_password', 'error', _('Please fill in all required fields correctly.'));
            return false;
        }

        return true;
    }

    private function processPasswordChange(): bool
    {
        [$success, $message] = $this->passwordService->changePassword(
            $this->httpRequest->getPostParam('old_password'),
            $this->httpRequest->getPostParam('new_password')
        );

        if ($success) {
            $this->services()->auditService()->logPasswordChange();

            $sessionEntity = new SessionEntity($message, 'success');
            $this->authService->logout($sessionEntity);
            return true;
        }
        $this->setMessage('change_password', 'error', $message);
        return false;
    }

    private function validatePasswordPolicy(): bool
    {
        $newPassword = $this->httpRequest->getPostParam('new_password');
        $policyErrors = $this->policyService->validatePassword($newPassword);

        if (!empty($policyErrors)) {
            $this->setMessage('change_password', 'error', array_shift($policyErrors));
            return false;
        }

        return true;
    }
}
