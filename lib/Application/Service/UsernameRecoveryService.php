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
use Poweradmin\Infrastructure\Repository\DbUsernameRecoveryRepository;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Psr\Log\LoggerInterface;
use PDO;

class UsernameRecoveryService
{
    private DbUsernameRecoveryRepository $recoveryRepository;
    private UserRepository $userRepository;
    private MailService $mailService;
    private ConfigurationInterface $config;
    private IpAddressRetriever $ipRetriever;
    private LoggerInterface $logger;
    private EmailTemplateService $templateService;
    private PDO $db;

    public function __construct(
        DbUsernameRecoveryRepository $recoveryRepository,
        UserRepository $userRepository,
        MailService $mailService,
        ConfigurationInterface $config,
        IpAddressRetriever $ipRetriever,
        LoggerInterface $logger,
        PDO $db
    ) {
        $this->recoveryRepository = $recoveryRepository;
        $this->userRepository = $userRepository;
        $this->mailService = $mailService;
        $this->config = $config;
        $this->ipRetriever = $ipRetriever;
        $this->logger = $logger;
        $this->db = $db;
        $this->templateService = new EmailTemplateService($config);
    }

    /**
     * Check if username recovery is enabled
     *
     * @return bool True if username recovery is enabled
     */
    public function isEnabled(): bool
    {
        return $this->config->get('security', 'username_recovery.enabled', false);
    }

    /**
     * Create a username recovery request
     *
     * This method handles the entire username recovery flow:
     * 1. Validates rate limits
     * 2. Finds all accounts associated with the email
     * 3. Sends recovery email with username information
     * 4. Records the request for rate limiting
     *
     * Security: Always returns true to prevent email enumeration attacks
     *
     * @param string $email Email address to recover username for
     * @return bool Always returns true for security (don't reveal if email exists)
     */
    public function createRecoveryRequest(string $email): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        // Clean up old requests before processing new request
        $this->cleanupOldRequests();

        $ip = $this->ipRetriever->getClientIp();

        // Check rate limits
        if (!$this->checkRateLimit($email, $ip)) {
            $this->logger->warning('Username recovery rate limit exceeded', [
                'email' => $email,
                'ip' => $ip,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            return true; // Return true to not reveal rate limiting
        }

        // Find all users with this email address
        $users = $this->getUsersByEmail($email);

        if (empty($users)) {
            $this->logger->info('Username recovery requested for non-existent email', [
                'email' => $email,
                'ip' => $ip,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            // Still record the attempt for rate limiting
            $this->recoveryRepository->create([
                'email' => $email,
                'ip_address' => $ip
            ]);
            return true; // Return true to not reveal if email exists
        }

        // Extract usernames from all user records
        $usernames = array_column($users, 'username');
        $fullname = $users[0]['fullname'] ?? '';

        // Send recovery email with all usernames
        $emailSent = $this->sendRecoveryEmail($email, $usernames, $fullname);

        // Record the recovery request
        $success = $this->recoveryRepository->create([
            'email' => $email,
            'ip_address' => $ip
        ]);

        if ($success) {
            $this->logger->info('Username recovery request processed', [
                'email' => $email,
                'ip' => $ip,
                'email_sent' => $emailSent,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            $this->logger->error('Failed to record username recovery request', [
                'email' => $email,
                'ip' => $ip,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        return true;
    }

    /**
     * Get all users associated with an email address
     *
     * @param string $email Email address to search for
     * @return array Array of user data (empty if none found)
     */
    private function getUsersByEmail(string $email): array
    {
        $query = "SELECT id, username, fullname, email, auth_method, active
                  FROM users
                  WHERE email = :email
                  AND active = 1
                  ORDER BY username ASC";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':email' => $email]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check rate limiting for username recovery requests
     *
     * Rate limits:
     * - Per email: Configurable attempts per time window
     * - Minimum time between requests: Configurable seconds
     * - Per IP: 2x the email limit to prevent distributed attacks
     *
     * @param string $email Email address
     * @param string $ip IP address
     * @return bool True if request is allowed, false if rate limited
     */
    private function checkRateLimit(string $email, string $ip): bool
    {
        $attempts = $this->config->get('security', 'username_recovery.rate_limit_attempts', 3);
        $window = $this->config->get('security', 'username_recovery.rate_limit_window', 3600);
        $minTime = $this->config->get('security', 'username_recovery.min_time_between_requests', 300);

        // Check email rate limit
        $recentAttempts = $this->recoveryRepository->countRecentAttempts($email, $window);
        if ($recentAttempts >= $attempts) {
            return false;
        }

        // Check minimum time between requests
        $lastAttempt = $this->recoveryRepository->getLastAttemptTime($email);
        if ($lastAttempt && (time() - strtotime($lastAttempt)) < $minTime) {
            return false;
        }

        // Check IP rate limit (allow 2x attempts per IP)
        $ipAttempts = $this->recoveryRepository->countRecentAttemptsByIp($ip, $window);
        if ($ipAttempts >= ($attempts * 2)) {
            return false;
        }

        return true;
    }

    /**
     * Send username recovery email
     *
     * @param string $email Email address
     * @param array $usernames Usernames associated with the email
     * @param string $name User's full name
     * @return bool True if email was sent successfully
     */
    private function sendRecoveryEmail(string $email, array $usernames, string $name): bool
    {
        $loginUrl = $this->getLoginUrl();
        $templates = $this->templateService->renderUsernameRecoveryEmail($name, $usernames, $loginUrl);

        $result = $this->mailService->sendMail($email, $templates['subject'], $templates['html'], $templates['text']);

        if ($result) {
            $this->logger->info('Username recovery email sent', [
                'email' => $email,
                'username_count' => count($usernames),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            $this->logger->error('Failed to send username recovery email', [
                'email' => $email,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        return $result;
    }

    /**
     * Get login page URL
     *
     * @return string Full URL to login page
     */
    private function getLoginUrl(): string
    {
        $urlService = new UrlService($this->config);
        return $urlService->getLoginUrl();
    }

    /**
     * Clean up old recovery request records
     *
     * This method can be called periodically to clean up old records.
     * Default: Delete records older than 30 days
     *
     * @param int $days Number of days to keep records (default: 30)
     * @return void
     */
    public function cleanupOldRequests(int $days = 30): void
    {
        $deleted = $this->recoveryRepository->deleteOlderThan($days);
        if ($deleted > 0) {
            $this->logger->debug('Cleaned up old username recovery requests', [
                'count' => $deleted,
                'older_than_days' => $days,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }
}
