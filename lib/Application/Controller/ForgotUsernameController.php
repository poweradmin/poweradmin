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
use Poweradmin\Application\Service\UsernameRecoveryService;
use Poweradmin\Application\Service\RecaptchaService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbUsernameRecoveryRepository;
use Poweradmin\Domain\Service\SessionKeys;

/**
 * Handles the forgot-username form: sends the username to the submitted email address if it is on file.
 */
class ForgotUsernameController extends BaseController
{
    private UsernameRecoveryService $usernameRecoveryService;
    private RecaptchaService $recaptchaService;
    private UserContextService $userContextService;
    private CsrfTokenService $csrfTokenService;
    private ClientContext $client;

    public function __construct(array $request)
    {
        parent::__construct($request, false); // No authentication required for forgot username

        // Create our own CSRF token service
        $this->csrfTokenService = new CsrfTokenService();

        // Create UsernameRecoveryService with dependencies
        try {
            $recoveryRepository = new DbUsernameRecoveryRepository($this->db, $this->config);
            $mailService = new MailService($this->config, $this->logger);
            $this->client = $this->services()->clientContext();

            $this->usernameRecoveryService = new UsernameRecoveryService(
                $recoveryRepository,
                $mailService,
                $this->config,
                $this->client,
                $this->logger,
                $this->db
            );

            $this->recaptchaService = new RecaptchaService($this->config);
            $this->userContextService = new UserContextService();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize username recovery controller', [
                'error' => $e->getMessage(),
                'origin' => $e->getFile() . ':' . $e->getLine()
            ]);
            throw $e; // Re-throw to let the application handle it
        }
    }

    /**
     * This flow validates its own one-shot `username_recovery_token` instead of the
     * session-wide form token.
     */
    protected function requiresCsrfValidation(): bool
    {
        return false;
    }

    public function run(): void
    {
        // Check if username recovery is enabled
        if (!$this->usernameRecoveryService->isEnabled()) {
            $this->logger->warning('Username recovery attempt while feature is disabled', [
                'ip' => $this->client->ip,
                'user_agent' => $this->client->userAgent,
                'browser' => $this->client->browser,
                'is_bot' => $this->client->isBot,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showError('Username recovery functionality is disabled.');
            return;
        }

        // Already logged in users shouldn't access this page
        if ($this->userContextService->isAuthenticated()) {
            $this->logger->info('Authenticated user attempted to access username recovery', [
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
            $this->handleUsernameRecoveryRequest();
        } else {
            $this->showUsernameRecoveryForm();
        }
    }

    private function handleUsernameRecoveryRequest(): void
    {
        $ipAddress = $this->client->ip;
        $userAgent = $this->client->userAgent;

        // Verify CSRF token manually to handle errors properly
        if ($this->config->get('security', 'global_token_validation', true)) {
            $token = $this->httpRequest->getPostParam('username_recovery_token', '');

            if (!$this->csrfTokenService->validateToken($token, SessionKeys::USERNAME_RECOVERY_TOKEN)) {
                $this->logger->warning('Username recovery failed - invalid CSRF token', [
                    'ip' => $ipAddress,
                    'user_agent' => $userAgent,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                $this->showUsernameRecoveryForm('Invalid security token. Please try again.');
                return;
            }

            // Clear the token after use
            unset($_SESSION[SessionKeys::USERNAME_RECOVERY_TOKEN]);
        }

        // Verify reCAPTCHA if enabled
        if ($this->recaptchaService->isEnabled()) {
            $recaptchaToken = $this->httpRequest->getPostParam('g-recaptcha-response', '');
            if (!$this->recaptchaService->verify($recaptchaToken, $ipAddress, 'forgot_username')) {
                $this->logger->warning('Username recovery failed - reCAPTCHA verification failed', [
                    'ip' => $ipAddress,
                    'user_agent' => $userAgent,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                $this->showUsernameRecoveryForm('reCAPTCHA verification failed. Please try again.');
                return;
            }
        }

        $email = trim($this->httpRequest->getPostParam('email', ''));

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->info('Username recovery failed - invalid email format', [
                'email' => $email,
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showUsernameRecoveryForm('Please enter a valid email address.');
            return;
        }

        // Log username recovery request attempt
        $this->logger->info('Username recovery request initiated', [
            'email' => $email,
            'ip' => $ipAddress,
            'user_agent' => $userAgent,
            'browser' => $this->client->browser,
            'is_bot' => $this->client->isBot,
            'referrer' => $_SERVER['HTTP_REFERER'] ?? 'none',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        try {
            // Create username recovery request
            $this->usernameRecoveryService->createRecoveryRequest($email);

            $this->createAuditService()->logUsernameRecovery($email);

            // Always show success message (for security - don't reveal if email exists)
            $this->showSuccessMessage();
        } catch (\PDOException $e) {
            // Database error - log detailed error but show generic message to user
            $this->logger->error('Username recovery failed - database error', [
                'email' => $email,
                'ip' => $ipAddress,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showUsernameRecoveryForm('A system error occurred. Please try again later or contact support if the problem persists.');
        } catch (\Exception $e) {
            // Generic error - log and show user-friendly message
            $this->logger->error('Username recovery failed - unexpected error', [
                'email' => $email,
                'ip' => $ipAddress,
                'error' => $e->getMessage(),
                'origin' => $e->getFile() . ':' . $e->getLine(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $this->showUsernameRecoveryForm('An unexpected error occurred. Please try again later.');
        }
    }

    private function showUsernameRecoveryForm(string $error = ''): void
    {
        // Log form display with error if present
        if ($error) {
            $this->logger->debug('Username recovery form displayed with error', [
                'error' => $error,
                'ip' => $this->client->ip,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        // Generate a new token for username recovery
        $usernameRecoveryToken = $this->csrfTokenService->generateToken();
        $_SESSION[SessionKeys::USERNAME_RECOVERY_TOKEN] = $usernameRecoveryToken;

        $this->render('forgot_username.html', [
            'error' => $error,
            'username_recovery_token' => $usernameRecoveryToken,
            'recaptcha_enabled' => $this->recaptchaService->isEnabled(),
            'recaptcha_site_key' => $this->recaptchaService->getSiteKey(),
            'recaptcha_version' => $this->recaptchaService->getVersion(),
        ]);
    }

    private function showSuccessMessage(): void
    {
        $this->render('forgot_username.html', [
            'success' => true,
            'message' => 'If an account exists with that email address, you will receive your username shortly.',
            'recaptcha_enabled' => false,
        ]);
    }
}
