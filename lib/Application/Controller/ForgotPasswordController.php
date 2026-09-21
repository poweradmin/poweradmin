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

use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\BaseController;
use Poweradmin\Application\Service\PasswordResetService;
use Poweradmin\Application\Service\RecaptchaService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\SessionKeys;

/**
 * Handles the forgot-password form: takes an email address and sends the password reset link.
 */
class ForgotPasswordController extends BaseController
{
    private PasswordResetService $passwordResetService;
    private RecaptchaService $recaptchaService;
    private UserContextService $userContextService;
    private CsrfTokenService $csrfTokenService;
    private ClientContext $client;

    public function __construct(array $request)
    {
        parent::__construct($request, false); // No authentication required for forgot password

        // Create our own CSRF token service
        $this->csrfTokenService = new CsrfTokenService();

        // Create PasswordResetService with dependencies
        $tokenRepository = $this->services()->passwordResetTokenRepository();
        $userRepository = $this->services()->userRepository();
        $mailService = new MailService($this->config, $this->logger);
        $authService = UserAuthenticationService::fromConfig($this->config);
        $this->client = $this->services()->clientContext();

        $this->passwordResetService = new PasswordResetService(
            $tokenRepository,
            $userRepository,
            $mailService,
            $this->config,
            $authService,
            $this->client,
            $this->logger
        );

        $this->recaptchaService = new RecaptchaService($this->config);
        $this->userContextService = new UserContextService();
    }

    /**
     * This flow validates its own one-shot `password_reset_token` instead of the
     * session-wide form token.
     */
    protected function requiresCsrfValidation(): bool
    {
        return false;
    }

    public function run(): void
    {
        // Check if password reset is enabled
        if (!$this->passwordResetService->isEnabled()) {
            $this->logger->warning('Password reset attempt while feature is disabled', [
                'ip' => $this->client->ip,
                'user_agent' => $this->client->userAgent,
                'browser' => $this->client->browser,
                'is_bot' => $this->client->isBot,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showError('Password reset functionality is disabled.');
            return;
        }

        // Already logged in users shouldn't access this page
        if ($this->userContextService->isAuthenticated()) {
            $this->logger->info('Authenticated user attempted to access password reset', [
                'user_id' => $this->userContextService->getLoggedInUserId(),
                'username' => $this->userContextService->getLoggedInUsername(),
                'ip' => $this->client->ip,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
            $this->services()->redirectService()->redirectTo($baseUrlPrefix . '/');
            return;
        }

        if ($this->isPost()) {
            $this->handlePasswordResetRequest();
        } else {
            $this->showPasswordResetForm();
        }
    }

    private function handlePasswordResetRequest(): void
    {
        $ipAddress = $this->client->ip;
        $userAgent = $this->client->userAgent;

        // Verify CSRF token manually to handle errors properly
        if ($this->config->get('security', 'global_token_validation', true)) {
            $token = $this->httpRequest->getPostParam('password_reset_token', '');

            if (!$this->csrfTokenService->validateToken($token, SessionKeys::PASSWORD_RESET_TOKEN)) {
                $this->logger->warning('Password reset failed - invalid CSRF token', [
                    'ip' => $ipAddress,
                    'user_agent' => $userAgent,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                $this->showPasswordResetForm('Invalid security token. Please try again.');
                return;
            }

            // Clear the token after use
            unset($_SESSION[SessionKeys::PASSWORD_RESET_TOKEN]);
        }

        // Verify reCAPTCHA if enabled
        if ($this->recaptchaService->isEnabled()) {
            $recaptchaToken = $this->httpRequest->getPostParam('g-recaptcha-response', '');
            if (!$this->recaptchaService->verify($recaptchaToken, $ipAddress, 'forgot_password')) {
                $this->logger->warning('Password reset failed - reCAPTCHA verification failed', [
                    'ip' => $ipAddress,
                    'user_agent' => $userAgent,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                $this->showPasswordResetForm('reCAPTCHA verification failed. Please try again.');
                return;
            }
        }

        $email = trim($this->httpRequest->getPostParam('email', ''));

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->info('Password reset failed - invalid email format', [
                'email' => $email,
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showPasswordResetForm('Please enter a valid email address.');
            return;
        }

        // Check if user's authentication method allows password reset
        $canReset = $this->passwordResetService->canUserResetPassword($email);
        if (!$canReset['allowed']) {
            // Respond exactly as for a normal request - revealing that the account
            // exists or which backend it uses would defeat the anti-enumeration
            // design. The reset simply does not proceed (no email is sent).
            $this->logger->info('Password reset blocked - user uses external authentication', [
                'email' => $email,
                'auth_method' => $canReset['auth_method'],
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showSuccessMessage();
            return;
        }

        // Log password reset request attempt
        $this->logger->info('Password reset request initiated', [
            'email' => $email,
            'ip' => $ipAddress,
            'user_agent' => $userAgent,
            'browser' => $this->client->browser,
            'is_bot' => $this->client->isBot,
            'referrer' => $_SERVER['HTTP_REFERER'] ?? 'none',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        try {
            // Create password reset request
            $this->passwordResetService->createResetRequest($email);

            $this->services()->auditService()->logPasswordResetRequest($email);

            // Always show success message (for security - don't reveal if email exists)
            $this->showSuccessMessage();
        } catch (\PDOException $e) {
            // Database error - log detailed error but show generic message to user
            $this->logger->error('Password reset failed - database error', [
                'email' => $email,
                'ip' => $ipAddress,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showPasswordResetForm('A system error occurred. Please try again later or contact support if the problem persists.');
        } catch (\Exception $e) {
            // Generic error - log and show user-friendly message
            $this->logger->error('Password reset failed - unexpected error', [
                'email' => $email,
                'ip' => $ipAddress,
                'error' => $e->getMessage(),
                'origin' => $e->getFile() . ':' . $e->getLine(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showPasswordResetForm('An unexpected error occurred. Please try again later.');
        }
    }

    private function showPasswordResetForm(string $error = ''): void
    {
        // Log form display with error if present
        if ($error) {
            $this->logger->debug('Password reset form displayed with error', [
                'error' => $error,
                'ip' => $this->client->ip,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        // Generate a new token for password reset
        $passwordResetToken = $this->csrfTokenService->generateToken();
        $_SESSION[SessionKeys::PASSWORD_RESET_TOKEN] = $passwordResetToken;

        $this->render('forgot_password.html', [
            'error' => $error,
            'password_reset_token' => $passwordResetToken,
            'recaptcha_enabled' => $this->recaptchaService->isEnabled(),
            'recaptcha_site_key' => $this->recaptchaService->getSiteKey(),
            'recaptcha_version' => $this->recaptchaService->getVersion(),
        ]);
    }

    private function showSuccessMessage(): void
    {
        $this->render('forgot_password.html', [
            'success' => true,
            'message' => 'If an account exists with that email address, you will receive a password reset link shortly.',
            'recaptcha_enabled' => false,
        ]);
    }
}
