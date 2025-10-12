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
use Poweradmin\Application\Service\MailService;
use Poweradmin\BaseController;
use Poweradmin\Application\Service\UsernameRecoveryService;
use Poweradmin\Application\Service\RecaptchaService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbUsernameRecoveryRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Service\RedirectService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Poweradmin\Infrastructure\Utility\UserAgentService;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;

class ForgotUsernameController extends BaseController
{
    private UsernameRecoveryService $usernameRecoveryService;
    private RecaptchaService $recaptchaService;
    private UserContextService $userContextService;
    private CsrfTokenService $csrfTokenService;
    private Logger $logger;
    private IpAddressRetriever $ipRetriever;
    private UserAgentService $userAgentService;

    public function __construct(array $request)
    {
        parent::__construct($request, false); // No authentication required for forgot username

        // Create our own CSRF token service
        $this->csrfTokenService = new CsrfTokenService();

        // Create UsernameRecoveryService with dependencies
        $configManager = ConfigurationManager::getInstance();

        // Create logger instance early for error logging
        $logHandler = LoggerHandlerFactory::create($configManager->getAll());
        $logLevel = $configManager->get('logging', 'level', 'info');
        $this->logger = new Logger($logHandler, $logLevel);

        try {
            $recoveryRepository = new DbUsernameRecoveryRepository($this->db, $configManager);
            $userRepository = new DbUserRepository($this->db, $configManager);
            $mailService = new MailService($configManager, null);
            $this->ipRetriever = new IpAddressRetriever($_SERVER);
            $this->userAgentService = new UserAgentService($_SERVER);

            $this->usernameRecoveryService = new UsernameRecoveryService(
                $recoveryRepository,
                $userRepository,
                $mailService,
                $configManager,
                $this->ipRetriever,
                $this->logger,
                $this->db
            );

            $this->recaptchaService = new RecaptchaService($this->config);
            $this->userContextService = new UserContextService();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize username recovery controller', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Re-throw to let the application handle it
        }
    }

    public function run(): void
    {
        // Check if username recovery is enabled
        if (!$this->usernameRecoveryService->isEnabled()) {
            $this->logger->warning('Username recovery attempt while feature is disabled', [
                'ip' => $this->ipRetriever->getClientIp(),
                'user_agent' => $this->userAgentService->getUserAgent(),
                'browser' => $this->userAgentService->getBrowserInfo(),
                'is_bot' => $this->userAgentService->isBot(),
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
                'ip' => $this->ipRetriever->getClientIp(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $redirectService = new RedirectService();
            $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
            $redirectService->redirectTo($baseUrlPrefix . '/');
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
        $ipAddress = $this->ipRetriever->getClientIp();
        $userAgent = $this->userAgentService->getUserAgent();

        // Verify CSRF token manually to handle errors properly
        if ($this->config->get('security', 'global_token_validation', true)) {
            $token = $_POST['username_recovery_token'] ?? '';

            if (!$this->csrfTokenService->validateToken($token, 'username_recovery_token')) {
                $this->logger->warning('Username recovery failed - invalid CSRF token', [
                    'ip' => $ipAddress,
                    'user_agent' => $userAgent,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                $this->showUsernameRecoveryForm('Invalid security token. Please try again.');
                return;
            }

            // Clear the token after use
            unset($_SESSION['username_recovery_token']);
        }

        // Verify reCAPTCHA if enabled
        if ($this->recaptchaService->isEnabled()) {
            $recaptchaToken = $_POST['g-recaptcha-response'] ?? '';
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

        $email = trim($_POST['email'] ?? '');

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
            'browser' => $this->userAgentService->getBrowserInfo(),
            'is_bot' => $this->userAgentService->isBot(),
            'referrer' => $_SERVER['HTTP_REFERER'] ?? 'none',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        try {
            // Create username recovery request
            $this->usernameRecoveryService->createRecoveryRequest($email);

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
                'trace' => $e->getTraceAsString(),
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
                'ip' => $this->ipRetriever->getClientIp(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        // Generate a new token for username recovery
        $usernameRecoveryToken = $this->csrfTokenService->generateToken();
        $_SESSION['username_recovery_token'] = $usernameRecoveryToken;

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
        ]);
    }
}
