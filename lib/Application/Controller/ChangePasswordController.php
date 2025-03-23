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
use Poweradmin\Infrastructure\Configuration\PasswordPolicyConfig;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Service\RedirectService;
use Valitron\Validator;

class ChangePasswordController extends BaseController
{
    private AuthenticationService $authService;
    private PasswordPolicyService $policyService;
    private Request $request;
    private PasswordChangeService $passwordService;

    private const VALIDATION_CONFIG = [
        'rules' => [
            'required' => [
                ['old_password'],
                ['new_password'],
                ['new_password2'],
            ],
            'equals' => [
                ['new_password2', 'new_password'],
            ]
        ],
        'labels' => [
            'old_password' => 'Current password',
            'new_password' => 'New password',
            'new_password2' => 'Repeat password'
        ]
    ];

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->request = new Request();
        $sessionService = new SessionService();
        $redirectService = new RedirectService();
        $this->authService = new AuthenticationService($sessionService, $redirectService);
        $this->policyService = new PasswordPolicyService(new PasswordPolicyConfig());
        $userAuthService = new UserAuthenticationService(
            $this->config('password_encryption'),
            $this->config('password_encryption_cost')
        );
        $userRepository = new DbUserRepository($this->db);
        $userContextService = new UserContextService();
        $this->passwordService = new PasswordChangeService($userRepository, $userAuthService, $userContextService);
    }

    public function run(): void
    {
        $this->checkCondition($_SESSION["auth_used"] == 'ldap', _('LDAP users cannot change their password here. Please contact your administrator.'));

        $policyConfig = $this->policyService->getPolicyConfig();

        if (!$this->isPost()) {
            $this->renderChangePasswordForm($policyConfig);
            return;
        }

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
        $validator = new Validator($this->request->getPostParams());
        $validator->rules(self::VALIDATION_CONFIG['rules']);
        $validator->labels(self::VALIDATION_CONFIG['labels']);

        if (!$validator->validate()) {
            $validationErrors = $validator->errors();
            $firstError = reset($validationErrors);
            $errorMessage = is_array($firstError) ? reset($firstError) : $firstError;
            $this->setMessage('change_password', 'error', $errorMessage);
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
