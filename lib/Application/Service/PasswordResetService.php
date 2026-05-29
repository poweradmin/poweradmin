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
     * Check if a user's authentication method allows password reset
     *
     * @param string $email User email address
     * @return array Result array with 'allowed' boolean and optional 'auth_method' for blocked users
     */
    public function canUserResetPassword(string $email): array
    {
        $user = $this->userRepository->getUserByEmail($email);

        if (!$user) {
            // Don't reveal if user exists - return allowed=true
            return ['allowed' => true];
        }

        $authMethod = $user['auth_method'] ?? 'sql';
        $blockedMethods = ['oidc', 'saml', 'ldap'];

        if (in_array($authMethod, $blockedMethods, true)) {
            return [
                'allowed' => false,
                'auth_method' => $authMethod
            ];
        }

        return ['allowed' => true];
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

        // Bail before persistence so a misconfigured application_url
        // doesn't burn rate-limit budget on tokens that can't be emailed.
        if (empty($this->config->get('interface', 'application_url', ''))) {
            $this->logger->error('Password reset email NOT sent: interface.application_url must be configured to build a trustworthy reset link', [
                'email' => $email,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return true;
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

        // Check if user's authentication method allows password reset
        // OIDC, SAML, and LDAP users should only authenticate through their external providers
        $authMethod = $user['auth_method'] ?? 'sql';
        if (in_array($authMethod, ['oidc', 'saml', 'ldap'], true)) {
            $this->logger->warning('Password reset blocked for external auth user', [
                'email' => $email,
                'auth_method' => $authMethod,
                'user_id' => $user['id'],
                'ip' => $ip,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return true; // Return true to not reveal auth method
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
     *
     * Built strictly from interface.application_url so the link in the email
     * is never influenced by the Host header of the requesting client.
     */
    private function getResetUrl(string $token): string
    {
        $urlService = new UrlService($this->config, $this->logger);
        return $urlService->getEmailUrl('/password/reset?token=' . urlencode($token)) ?? '';
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

        // Refuse candidates already in the stored hash format - blocks pass-the-hash
        // from a DB-read leak. Matches the API key repository defense.
        if (str_starts_with($token, 'sha256$')) {
            $this->logger->warning('Password reset token rejected: candidate is in stored hash format', [
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return null;
        }

        // Clean up expired tokens before validation
        $this->cleanupExpiredTokens();

        // Indexed lookup by hash - replaces the previous full-scan of active tokens
        // and the row no longer contains a plaintext token at rest.
        $hashedToken = DbPasswordResetTokenRepository::hashToken($token);
        $tokenData = $this->tokenRepository->findByToken($hashedToken);

        if (!$tokenData) {
            $this->logger->warning('Invalid password reset token used', [
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return null;
        }

        $user = $this->userRepository->getUserByEmail($tokenData['email']);
        if (!$user) {
            return null;
        }

        // External-auth users cannot reset their local password; their account
        // may have switched to OIDC/SAML/LDAP since the token was minted.
        $authMethod = $user['auth_method'] ?? 'sql';
        if (in_array($authMethod, ['oidc', 'saml', 'ldap'], true)) {
            $this->logger->warning('Password reset token validation blocked for external auth user', [
                'email' => $tokenData['email'],
                'auth_method' => $authMethod,
                'user_id' => $user['id'],
                'token_id' => $tokenData['id'],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return null;
        }

        return [
            'user' => $user,
            'token_id' => $tokenData['id']
        ];
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
