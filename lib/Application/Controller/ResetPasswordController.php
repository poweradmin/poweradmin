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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\BaseController;
use Poweradmin\Application\Service\PasswordResetService;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Service\RedirectService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Poweradmin\Infrastructure\Utility\UserAgentService;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;

class ResetPasswordController extends BaseController
{
    private PasswordResetService $passwordResetService;
    private PasswordPolicyService $passwordPolicyService;
    private UserContextService $userContextService;
    private CsrfTokenService $csrfTokenService;
    private Logger $logger;
    private IpAddressRetriever $ipRetriever;
    private UserAgentService $userAgentService;
    private ?string $token = null;

    public function __construct(array $request)
    {
        parent::__construct($request, false); // No authentication required for password reset

        // Create our own CSRF token service
        $this->csrfTokenService = new CsrfTokenService();

        // Create PasswordResetService with dependencies
        $configManager = ConfigurationManager::getInstance();
        $tokenRepository = new DbPasswordResetTokenRepository($this->db, $configManager);
        $userRepository = new DbUserRepository($this->db, $configManager);
        $mailService = new MailService($configManager, null);
        $authService = new UserAuthenticationService(
            $configManager->get('security', 'password_encryption', 'bcrypt'),
            $configManager->get('security', 'password_cost', 12)
        );
        $this->ipRetriever = new IpAddressRetriever($_SERVER);
        $this->userAgentService = new UserAgentService($_SERVER);

        // Create logger instance
        $logHandler = LoggerHandlerFactory::create($configManager->getAll());
        $logLevel = $configManager->get('logging', 'level', 'info');
        $this->logger = new Logger($logHandler, $logLevel);

        $this->passwordResetService = new PasswordResetService(
            $tokenRepository,
            $userRepository,
            $mailService,
            $configManager,
            $authService,
            $this->ipRetriever,
            $this->logger
        );

        $this->passwordPolicyService = new PasswordPolicyService($this->config);
        $this->userContextService = new UserContextService();

        // Extract token from URL parameters
        $this->token = $_GET['token'] ?? null;
    }

    public function run(): void
    {
        // Check if password reset is enabled
        if (!$this->passwordResetService->isEnabled()) {
            $this->logger->warning('Password reset page accessed while feature is disabled', [
                'ip' => $this->ipRetriever->getClientIp(),
                'user_agent' => $this->userAgentService->getUserAgent(),
                'browser' => $this->userAgentService->getBrowserInfo(),
                'is_bot' => $this->userAgentService->isBot(),
                'token' => $this->token ?? 'none',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showErrorMessage('Password reset functionality is disabled.');
            return;
        }

        // Already logged in users shouldn't access this page
        if ($this->userContextService->isAuthenticated()) {
            $this->logger->info('Authenticated user attempted to access password reset page', [
                'user_id' => $this->userContextService->getLoggedInUserId(),
                'username' => $this->userContextService->getLoggedInUsername(),
                'ip' => $this->ipRetriever->getClientIp(),
                'token' => $this->token ?? 'none',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $redirectService = new RedirectService();
            $redirectService->redirectTo('/');
            return;
        }

        // Validate token
        if (!$this->token) {
            $this->logger->warning('Password reset page accessed without token', [
                'ip' => $this->ipRetriever->getClientIp(),
                'user_agent' => $this->userAgentService->getUserAgent(),
                'browser' => $this->userAgentService->getBrowserInfo(),
                'is_bot' => $this->userAgentService->isBot(),
                'referrer' => $_SERVER['HTTP_REFERER'] ?? 'none',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showErrorMessage('Invalid or missing password reset token.');
            return;
        }

        $tokenData = $this->passwordResetService->validateToken($this->token);
        if (!$tokenData) {
            $this->logger->warning('Invalid or expired password reset token presented', [
                'ip' => $this->ipRetriever->getClientIp(),
                'user_agent' => $this->userAgentService->getUserAgent(),
                'browser' => $this->userAgentService->getBrowserInfo(),
                'is_bot' => $this->userAgentService->isBot(),
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
            'ip' => $this->ipRetriever->getClientIp(),
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
        $ipAddress = $this->ipRetriever->getClientIp();
        $userId = $tokenData['user']['id'];
        $email = $tokenData['user']['email'];

        // Verify CSRF token manually to handle errors properly
        if ($this->config->get('security', 'global_token_validation', true)) {
            $token = $_POST['reset_password_token'] ?? '';

            if (!$this->csrfTokenService->validateToken($token, 'reset_password_token')) {
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
            unset($_SESSION['reset_password_token']);
        }

        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

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
        $policyErrors = $this->passwordPolicyService->validatePassword($password);
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
        if ($this->passwordResetService->resetPassword($this->token, $password)) {
            $this->logger->info('Password reset completed via web interface', [
                'user_id' => $userId,
                'email' => $email,
                'ip' => $ipAddress,
                'user_agent' => $this->userAgentService->getUserAgent(),
                'browser' => $this->userAgentService->getBrowserInfo(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);

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
                'ip' => $this->ipRetriever->getClientIp(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        // Generate a new token for password reset
        $resetPasswordToken = $this->csrfTokenService->generateToken();
        $_SESSION['reset_password_token'] = $resetPasswordToken;

        $this->render('reset_password.html', [
            'token' => $this->token,
            'email' => $tokenData['user']['email'],
            'error' => $error,
            'policy_errors' => $policyErrors,
            'reset_password_token' => $resetPasswordToken,
            'password_policy' => $this->passwordPolicyService->getPolicyConfig(),
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
