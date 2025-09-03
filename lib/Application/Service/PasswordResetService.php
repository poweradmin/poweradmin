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

namespace Poweradmin\Application\Service;

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Psr\Log\LoggerInterface;

class PasswordResetService
{
    private DbPasswordResetTokenRepository $tokenRepository;
    private UserRepository $userRepository;
    private MailService $mailService;
    private ConfigurationInterface $config;
    private UserAuthenticationService $authService;
    private IpAddressRetriever $ipRetriever;
    private LoggerInterface $logger;
    private EmailTemplateService $templateService;

    public function __construct(
        DbPasswordResetTokenRepository $tokenRepository,
        UserRepository $userRepository,
        MailService $mailService,
        ConfigurationInterface $config,
        UserAuthenticationService $authService,
        IpAddressRetriever $ipRetriever,
        LoggerInterface $logger
    ) {
        $this->tokenRepository = $tokenRepository;
        $this->userRepository = $userRepository;
        $this->mailService = $mailService;
        $this->config = $config;
        $this->authService = $authService;
        $this->ipRetriever = $ipRetriever;
        $this->logger = $logger;
        $this->templateService = new EmailTemplateService($config);
    }

    /**
     * Check if password reset is enabled
     */
    public function isEnabled(): bool
    {
        return $this->config->get('security', 'password_reset.enabled', false);
    }

    /**
     * Generate a cryptographically secure token
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Create a password reset request
     *
     * @param string $email
     * @return bool Always returns true for security (don't reveal if email exists)
     */
    public function createResetRequest(string $email): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        // Clean up expired tokens before processing new request
        $this->cleanupExpiredTokens();

        $ip = $this->ipRetriever->getClientIp();

        // Check rate limits
        if (!$this->checkRateLimit($email, $ip)) {
            $this->logger->warning('Password reset rate limit exceeded', [
                'email' => $email,
                'ip' => $ip,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return true; // Return true to not reveal rate limiting
        }

        // Check if user exists
        $user = $this->userRepository->getUserByEmail($email);
        if (!$user) {
            $this->logger->info('Password reset requested for non-existent email', [
                'email' => $email,
                'ip' => $ip,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return true; // Return true to not reveal if email exists
        }

        // Generate token
        $token = $this->generateToken();
        $expiresAt = time() + $this->config->get('security', 'password_reset.token_lifetime', 3600);

        // Store token
        $success = $this->tokenRepository->create([
            'email' => $email,
            'token' => $token,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'ip_address' => $ip
        ]);

        if ($success) {
            $emailSent = $this->sendResetEmail($email, $token, $user['fullname'] ?? '');

            $this->logger->info('Password reset token created', [
                'user_id' => $user['id'],
                'email' => $email,
                'ip' => $ip,
                'token_expires_at' => date('Y-m-d H:i:s', $expiresAt),
                'email_sent' => $emailSent,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            $this->logger->error('Failed to create password reset token', [
                'email' => $email,
                'ip' => $ip,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        return true;
    }

    /**
     * Check rate limiting for password reset requests
     */
    private function checkRateLimit(string $email, string $ip): bool
    {
        $attempts = $this->config->get('security', 'password_reset.rate_limit_attempts', 3);
        $window = $this->config->get('security', 'password_reset.rate_limit_window', 3600);
        $minTime = $this->config->get('security', 'password_reset.min_time_between_requests', 300);

        // Check email rate limit
        $recentAttempts = $this->tokenRepository->countRecentAttempts($email, $window);
        if ($recentAttempts >= $attempts) {
            return false;
        }

        // Check minimum time between requests
        $lastAttempt = $this->tokenRepository->getLastAttemptTime($email);
        if ($lastAttempt && (time() - strtotime($lastAttempt)) < $minTime) {
            return false;
        }

        // Check IP rate limit
        $ipAttempts = $this->tokenRepository->countRecentAttemptsByIp($ip, $window);
        if ($ipAttempts >= ($attempts * 2)) { // Allow 2x attempts per IP
            return false;
        }

        return true;
    }

    /**
     * Send password reset email
     */
    private function sendResetEmail(string $email, string $token, string $name): bool
    {
        $resetUrl = $this->getResetUrl($token);
        $expireTime = $this->config->get('security', 'password_reset.token_lifetime', 3600) / 60; // Convert to minutes

        $templates = $this->templateService->renderPasswordResetEmail($name, $resetUrl, $expireTime);

        return $this->mailService->sendMail($email, $templates['subject'], $templates['html'], $templates['text']);
    }

    /**
     * Get password reset URL
     */
    private function getResetUrl(string $token): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $basePath = rtrim($basePath, '/');

        return "$protocol://$host$basePath/auth/reset-password/" . urlencode($token);
    }

    /**
     * Validate a password reset token
     *
     * @param string $token
     * @return array|null Returns user data if valid, null otherwise
     */
    public function validateToken(string $token): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Clean up expired tokens before validation
        $this->cleanupExpiredTokens();

        // Find all non-expired tokens
        $tokens = $this->tokenRepository->findActiveTokens();

        foreach ($tokens as $tokenData) {
            if ($token === $tokenData['token']) {
                // Check if already used
                if ($tokenData['used']) {
                    $this->logger->warning('Attempted to use already used password reset token', [
                        'email' => $tokenData['email'],
                        'token_id' => $tokenData['id'],
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                    return null;
                }

                // Check expiration
                if (time() > strtotime($tokenData['expires_at'])) {
                    $this->logger->info('Expired password reset token used', [
                        'email' => $tokenData['email'],
                        'token_id' => $tokenData['id'],
                        'expired_at' => $tokenData['expires_at'],
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                    return null;
                }

                // Get user data
                $user = $this->userRepository->getUserByEmail($tokenData['email']);
                if ($user) {
                    return [
                        'user' => $user,
                        'token_id' => $tokenData['id']
                    ];
                }
            }
        }

        $this->logger->warning('Invalid password reset token used', [
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        return null;
    }

    /**
     * Reset password using a valid token
     *
     * @param string $token
     * @param string $newPassword
     * @return bool
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $validationResult = $this->validateToken($token);
        if (!$validationResult) {
            return false;
        }

        $user = $validationResult['user'];
        $tokenId = $validationResult['token_id'];

        // Hash the new password
        $hashedPassword = $this->authService->hashPassword($newPassword);

        // Update user password
        $success = $this->userRepository->updatePassword($user['id'], $hashedPassword);

        if ($success) {
            // Mark token as used
            $this->tokenRepository->markAsUsed($tokenId);

            // Alternative: Delete the used token immediately
            // $this->tokenRepository->deleteById($tokenId);

            $this->logger->info('Password reset completed successfully', [
                'user_id' => $user['id'],
                'username' => $user['username'] ?? 'unknown',
                'email' => $user['email'],
                'token_id' => $tokenId,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Clean up expired tokens
            $this->cleanupExpiredTokens();
        } else {
            $this->logger->error('Failed to update password during reset', [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'token_id' => $tokenId,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        return $success;
    }

    /**
     * Clean up expired tokens
     *
     * This method can be called from anywhere to clean up expired tokens.
     * It's automatically called during:
     * - Creating new password reset requests
     * - Validating tokens
     * - Successful password resets
     */
    public function cleanupExpiredTokens(): void
    {
        $deleted = $this->tokenRepository->deleteExpired();
        if ($deleted > 0) {
            $this->logger->debug('Cleaned up expired password reset tokens', [
                'count' => $deleted,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }
}
