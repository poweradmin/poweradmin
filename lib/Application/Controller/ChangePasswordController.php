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
 * Script that handles user password changes
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\PasswordChangeService;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Service\RedirectService;
use Symfony\Component\Validator\Constraints as Assert;

class ChangePasswordController extends BaseController
{
    private AuthenticationService $authService;
    private PasswordPolicyService $policyService;
    protected Request $request;
    private PasswordChangeService $passwordService;
    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->request = new Request();
        $sessionService = new SessionService();
        $redirectService = new RedirectService();
        $this->authService = new AuthenticationService($sessionService, $redirectService);
        $this->policyService = new PasswordPolicyService();

        // Get password encryption settings with fallback to defaults
        $passwordEncryption = $this->config->get('security', 'password_encryption', 'bcrypt');
        $passwordEncryptionCost = (int)$this->config->get('security', 'password_cost', 12);

        $userAuthService = new UserAuthenticationService(
            $passwordEncryption,
            $passwordEncryptionCost
        );
        $userRepository = new DbUserRepository($this->db, $this->config);
        $userContextService = new UserContextService();
        $this->passwordService = new PasswordChangeService($userRepository, $userAuthService, $userContextService);
    }

    public function run(): void
    {
        // Check for external authentication methods that don't allow password changes
        $authUsed = $_SESSION["auth_used"] ?? null;

        // Block external authentication users
        $externalAuthMethods = ['ldap', 'oidc', 'saml'];
        if (in_array($authUsed, $externalAuthMethods)) {
            $message = match ($authUsed) {
                'ldap' => _('LDAP users cannot change their password here. Please contact your administrator.'),
                'oidc', 'saml' => _('Users authenticated via Single Sign-On cannot change their password here. Please contact your administrator or change your password through your identity provider.'),
                default => _('External authentication users cannot change their password here. Please contact your administrator.')
            };
            $this->checkCondition(true, $message);
        }

        // Set the current page for navigation highlighting
        $this->requestData['page'] = 'change_password';

        $policyConfig = $this->policyService->getPolicyConfig();

        if (!$this->isPost()) {
            $this->renderChangePasswordForm($policyConfig);
            return;
        }

        // Make sure we have the latest POST data
        $this->request->refresh();

        $this->validateCsrfToken();

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
                    'value' => $this->request->getPostParam('new_password'),
                    'message' => 'Repeat password must match the new password.'
                ])
            ]
        ];

        $this->setValidationConstraints($constraints);
        $data = $this->request->getPostParams();

        if (!$this->doValidateRequest($data)) {
            $this->setMessage('change_password', 'error', _('Please fill in all required fields correctly.'));
            return false;
        }

        return true;
    }

    private function processPasswordChange(): bool
    {
        [$success, $message] = $this->passwordService->changePassword(
            $this->request->getPostParam('old_password'),
            $this->request->getPostParam('new_password')
        );

        if ($success) {
            $sessionEntity = new SessionEntity($message, 'success');
            $this->authService->logout($sessionEntity);
            return true;
        }

        // TODO: Consider logging the error instead of displaying this message to the user
        $this->setMessage('change_password', 'error', $message);
        return false;
    }

    private function validatePasswordPolicy(): bool
    {
        $newPassword = $this->request->getPostParam('new_password');
        $policyErrors = $this->policyService->validatePassword($newPassword);

        if (!empty($policyErrors)) {
            $this->setMessage('change_password', 'error', array_shift($policyErrors));
            return false;
        }

        return true;
    }
}
