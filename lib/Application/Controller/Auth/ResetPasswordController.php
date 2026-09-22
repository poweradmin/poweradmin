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

namespace Poweradmin\Application\Controller\Auth;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Application\Service\User\PasswordResetService;
use Poweradmin\Application\Service\User\PasswordPolicyService;
use Poweradmin\Application\Service\Auth\UserAuthenticationService;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;

/**
 * Handles the password reset form reached from the emailed token link and sets the new password.
 */
class ResetPasswordController extends BaseController
{
    private ?PasswordResetService $passwordResetService = null;
    private ?PasswordPolicyService $passwordPolicyService = null;
    private ?CsrfTokenService $csrfTokenService = null;
    private ?ClientContext $client = null;
    private ?string $token = null;

    public function __construct(array $request, ?ControllerEnvironment $environment = null)
    {
        parent::__construct($request, false, $environment); // No authentication required for password reset
        // Extract token from URL parameters
        $this->token = $this->httpRequest->getQueryParam('token');
    }

    private function csrfTokenService(): CsrfTokenService
    {
        return $this->csrfTokenService ??= new CsrfTokenService($this->session());
    }

    private function client(): ClientContext
    {
        return $this->client ??= $this->services()->clientContext();
    }

    private function passwordResetService(): PasswordResetService
    {
        return $this->passwordResetService ??= new PasswordResetService(
            $this->services()->passwordResetTokenRepository(),
            $this->services()->userRepository(),
            $this->services()->mailService(),
            $this->config,
            UserAuthenticationService::fromConfig($this->config),
            $this->client(),
            $this->logger,
            $this->services()->urlService()
        );
    }

    private function passwordPolicyService(): PasswordPolicyService
    {
        return $this->passwordPolicyService ??= $this->services()->passwordPolicyService();
    }

    /**
     * This flow validates its own one-shot `reset_password_token` instead of the
     * session-wide form token.
     */
    protected function requiresCsrfValidation(): bool
    {
        return false;
    }

    public function run(): void
    {
        // Check if password reset is enabled
        if (!$this->passwordResetService()->isEnabled()) {
            $this->logger->warning('Password reset page accessed while feature is disabled', [
                'ip' => $this->client()->ip,
                'user_agent' => $this->client()->userAgent,
                'browser' => $this->client()->browser,
                'is_bot' => $this->client()->isBot,
                'token' => $this->token ?? 'none',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showErrorMessage('Password reset functionality is disabled.');
            return;
        }

        // Already logged in users shouldn't access this page
        if ($this->getUserContextService()->isAuthenticated()) {
            $this->logger->info('Authenticated user attempted to access password reset page', [
                'user_id' => $this->getUserContextService()->getLoggedInUserId(),
                'username' => $this->getUserContextService()->getLoggedInUsername(),
                'ip' => $this->client()->ip,
                'token' => $this->token ?? 'none',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
            $this->services()->redirectService()->redirectTo($baseUrlPrefix . '/');
            return;
        }

        // Validate token
        if (!$this->token) {
            $this->logger->warning('Password reset page accessed without token', [
                'ip' => $this->client()->ip,
                'user_agent' => $this->client()->userAgent,
                'browser' => $this->client()->browser,
                'is_bot' => $this->client()->isBot,
                'referrer' => $_SERVER['HTTP_REFERER'] ?? 'none',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showErrorMessage('Invalid or missing password reset token.');
            return;
        }

        $tokenData = $this->passwordResetService()->validateToken($this->token);
        if (!$tokenData) {
            $this->logger->warning('Invalid or expired password reset token presented', [
                'ip' => $this->client()->ip,
                'user_agent' => $this->client()->userAgent,
                'browser' => $this->client()->browser,
                'is_bot' => $this->client()->isBot,
                'token_received' => $this->token,
                'token_length' => strlen($this->token),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showErrorMessage('Invalid or expired password reset token.');
            return;
        }

        // Log valid token access
        $this->logger->info('Valid password reset token accessed', [
            'user_id' => $tokenData['user']['id'],
            'email' => $tokenData['user']['email'],
            'ip' => $this->client()->ip,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        if ($this->isPost()) {
            $this->handlePasswordReset($tokenData);
        } else {
            $this->showPasswordResetForm($tokenData);
        }
    }

    private function handlePasswordReset(array $tokenData): void
    {
        $ipAddress = $this->client()->ip;
        $userId = $tokenData['user']['id'];
        $email = $tokenData['user']['email'];

        // Verify CSRF token manually to handle errors properly
        if ($this->config->get('security', 'global_token_validation', true)) {
            $token = $this->httpRequest->getPostParam('reset_password_token', '');

            if (!$this->csrfTokenService()->validateToken($token, AuthFlowSessionKeys::RESET_PASSWORD_TOKEN)) {
                $this->logger->warning('Password reset failed - invalid CSRF token', [
                    'user_id' => $userId,
                    'email' => $email,
                    'ip' => $ipAddress,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                $this->showPasswordResetForm($tokenData, 'Invalid security token. Please try again.');
                return;
            }

            // Clear the token after use
            $this->session()->remove(AuthFlowSessionKeys::RESET_PASSWORD_TOKEN);
        }

        $password = $this->httpRequest->getPostParam('password', '');
        $confirmPassword = $this->httpRequest->getPostParam('confirm_password', '');

        // Check if passwords match
        if ($password !== $confirmPassword) {
            $this->logger->info('Password reset failed - passwords do not match', [
                'user_id' => $userId,
                'email' => $email,
                'ip' => $ipAddress,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showPasswordResetForm($tokenData, 'Passwords do not match.');
            return;
        }

        // Validate password against policy
        $policyErrors = $this->passwordPolicyService()->validatePassword($password);
        if (!empty($policyErrors)) {
            $this->logger->info('Password reset failed - password policy violation', [
                'user_id' => $userId,
                'email' => $email,
                'policy_errors' => $policyErrors,
                'ip' => $ipAddress,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showPasswordResetForm($tokenData, '', $policyErrors);
            return;
        }

        // Log password reset attempt
        $this->logger->info('Password reset attempt', [
            'user_id' => $userId,
            'email' => $email,
            'ip' => $ipAddress,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Reset the password
        if ($this->passwordResetService()->resetPassword($this->token, $password)) {
            $this->logger->info('Password reset completed via web interface', [
                'user_id' => $userId,
                'email' => $email,
                'ip' => $ipAddress,
                'user_agent' => $this->client()->userAgent,
                'browser' => $this->client()->browser,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            $this->services()->auditService()->logPasswordReset((int)$userId);

            // Set success message and redirect to login
            $this->setMessage('login', 'success', 'Your password has been successfully reset. You can now log in with your new password.');
            $this->redirect('/login');
            return;
        } else {
            $this->logger->error('Password reset failed at final step', [
                'user_id' => $userId,
                'email' => $email,
                'ip' => $ipAddress,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showPasswordResetForm($tokenData, 'Failed to reset password. Please try again.');
        }
    }

    private function showPasswordResetForm(array $tokenData, string $error = '', array $policyErrors = []): void
    {
        // Log form display with error if present
        if ($error || !empty($policyErrors)) {
            $this->logger->debug('Password reset form displayed with error', [
                'user_id' => $tokenData['user']['id'],
                'email' => $tokenData['user']['email'],
                'error' => $error,
                'policy_errors' => $policyErrors,
                'ip' => $this->client()->ip,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        // Generate a new token for password reset
        $resetPasswordToken = $this->csrfTokenService()->generateToken();
        $this->session()->set(AuthFlowSessionKeys::RESET_PASSWORD_TOKEN, $resetPasswordToken);

        $this->render('reset_password.html', [
            'token' => $this->token,
            'email' => $tokenData['user']['email'],
            'error' => $error,
            'policy_errors' => $policyErrors,
            'reset_password_token' => $resetPasswordToken,
            'password_policy' => $this->passwordPolicyService()->getPolicyConfig(),
        ]);
    }

    private function showErrorMessage(string $message): void
    {
        $this->render('reset_password.html', [
            'error' => $message,
            'show_form' => false,
        ]);
    }
}
