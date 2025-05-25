<?php

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\BaseController;
use Poweradmin\Application\Service\PasswordResetService;
use Poweradmin\Application\Service\RecaptchaService;
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

class ForgotPasswordController extends BaseController
{
    private PasswordResetService $passwordResetService;
    private RecaptchaService $recaptchaService;
    private UserContextService $userContextService;
    private CsrfTokenService $csrfTokenService;
    private Logger $logger;
    private IpAddressRetriever $ipRetriever;
    private UserAgentService $userAgentService;

    public function __construct(array $request)
    {
        parent::__construct($request, false); // No authentication required for forgot password

        // Create our own CSRF token service
        $this->csrfTokenService = new CsrfTokenService();

        // Create PasswordResetService with dependencies
        $configManager = ConfigurationManager::getInstance();
        $tokenRepository = new DbPasswordResetTokenRepository($this->db);
        $userRepository = new DbUserRepository($this->db);
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

        $this->recaptchaService = new RecaptchaService($this->config);
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        // Check if password reset is enabled
        if (!$this->passwordResetService->isEnabled()) {
            $this->logger->warning('Password reset attempt while feature is disabled', [
                'ip' => $this->ipRetriever->getClientIp(),
                'user_agent' => $this->userAgentService->getUserAgent(),
                'browser' => $this->userAgentService->getBrowserInfo(),
                'is_bot' => $this->userAgentService->isBot(),
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
                'ip' => $this->ipRetriever->getClientIp(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $redirectService = new RedirectService();
            $redirectService->redirectTo('index.php');
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
        $ipAddress = $this->ipRetriever->getClientIp();
        $userAgent = $this->userAgentService->getUserAgent();

        // Verify CSRF token manually to handle errors properly
        if ($this->config->get('security', 'global_token_validation', true)) {
            $token = $_POST['password_reset_token'] ?? '';

            if (!$this->csrfTokenService->validateToken($token, 'password_reset_token')) {
                $this->logger->warning('Password reset failed - invalid CSRF token', [
                    'ip' => $ipAddress,
                    'user_agent' => $userAgent,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                $this->showPasswordResetForm('Invalid security token. Please try again.');
                return;
            }

            // Clear the token after use
            unset($_SESSION['password_reset_token']);
        }

        // Verify reCAPTCHA if enabled
        if ($this->recaptchaService->isEnabled()) {
            $recaptchaToken = $_POST['g-recaptcha-response'] ?? '';
            if (!$this->recaptchaService->verify($recaptchaToken)) {
                $this->logger->warning('Password reset failed - reCAPTCHA verification failed', [
                    'ip' => $ipAddress,
                    'user_agent' => $userAgent,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                $this->showPasswordResetForm('reCAPTCHA verification failed. Please try again.');
                return;
            }
        }

        $email = trim($_POST['email'] ?? '');

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

        // Log password reset request attempt
        $this->logger->info('Password reset request initiated', [
            'email' => $email,
            'ip' => $ipAddress,
            'user_agent' => $userAgent,
            'browser' => $this->userAgentService->getBrowserInfo(),
            'is_bot' => $this->userAgentService->isBot(),
            'referrer' => $_SERVER['HTTP_REFERER'] ?? 'none',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Create password reset request
        $this->passwordResetService->createResetRequest($email);

        // Always show success message (for security - don't reveal if email exists)
        $this->showSuccessMessage();
    }

    private function showPasswordResetForm(string $error = ''): void
    {
        // Log form display with error if present
        if ($error) {
            $this->logger->debug('Password reset form displayed with error', [
                'error' => $error,
                'ip' => $this->ipRetriever->getClientIp(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        // Generate a new token for password reset
        $passwordResetToken = $this->csrfTokenService->generateToken();
        $_SESSION['password_reset_token'] = $passwordResetToken;

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
        ]);
    }
}
