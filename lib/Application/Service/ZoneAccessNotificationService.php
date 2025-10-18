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

use PDO;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\LoggerInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Service for sending zone access change notifications
 *
 * This service handles email notifications when users are granted or revoked
 * access to DNS zones, improving collaboration awareness and audit trails.
 */
class ZoneAccessNotificationService
{
    private PDO $db;
    private ConfigurationManager $config;
    private MailService $mailService;
    private EmailTemplateService $emailTemplateService;
    private ZoneRepositoryInterface $zoneRepository;
    private UrlService $urlService;
    private ?LoggerInterface $logger;

    public function __construct(
        PDO $db,
        ConfigurationManager $config,
        MailService $mailService,
        EmailTemplateService $emailTemplateService,
        ZoneRepositoryInterface $zoneRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->mailService = $mailService;
        $this->emailTemplateService = $emailTemplateService;
        $this->zoneRepository = $zoneRepository;
        $this->urlService = new UrlService($config);
        $this->logger = $logger;
    }

    /**
     * Send notification when zone access is granted to a user
     *
     * @param int $zoneId Zone ID that access was granted to
     * @param int $newOwnerId User ID of the new owner
     * @param int $grantedById User ID of the person who granted access
     * @return bool True if notification was sent successfully
     */
    public function notifyAccessGranted(int $zoneId, int $newOwnerId, int $grantedById): bool
    {
        // Early return if notifications or mail are disabled
        if (!$this->isNotificationEnabled()) {
            return false;
        }

        try {
            // Get zone details
            $zoneName = $this->zoneRepository->getDomainNameById($zoneId);
            if (!$zoneName) {
                $this->logError("Zone not found with ID: $zoneId");
                return false;
            }

            // Get recipient (new owner) details
            $recipient = $this->getUserDetails($newOwnerId);
            if (!$recipient || empty($recipient['email'])) {
                $this->logWarning("User $newOwnerId has no email address, skipping notification");
                return false;
            }

            // Get granter details
            $granter = $this->getUserDetails($grantedById);
            if (!$granter) {
                $this->logError("User not found with ID: $grantedById");
                return false;
            }

            // Build absolute zone edit URL
            $zoneEditUrl = $this->urlService->getZoneEditUrl($zoneId);

            // Render email templates
            $templates = $this->emailTemplateService->renderZoneAccessGrantedEmail(
                $zoneName,
                $recipient['fullname'] ?: $recipient['username'],
                $granter['fullname'] ?: $granter['username'],
                $granter['email'] ?: '',
                date('Y-m-d H:i:s'),
                $zoneEditUrl
            );

            // Send email
            $result = $this->mailService->sendMail(
                $recipient['email'],
                $templates['subject'],
                $templates['html'],
                $templates['text']
            );

            if ($result) {
                $this->logInfo("Access granted notification sent for zone '$zoneName' to user '{$recipient['username']}'");
            } else {
                $this->logError("Failed to send access granted notification for zone '$zoneName' to user '{$recipient['username']}'");
            }

            return $result;
        } catch (LoaderError | RuntimeError | SyntaxError $e) {
            $this->logError("Template error sending access granted notification: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->logError("Error sending access granted notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when zone access is revoked from a user
     *
     * @param int $zoneId Zone ID that access was revoked from
     * @param int $removedOwnerId User ID of the removed owner
     * @param int $revokedById User ID of the person who revoked access
     * @return bool True if notification was sent successfully
     */
    public function notifyAccessRevoked(int $zoneId, int $removedOwnerId, int $revokedById): bool
    {
        // Early return if notifications or mail are disabled
        if (!$this->isNotificationEnabled()) {
            return false;
        }

        try {
            // Get zone details
            $zoneName = $this->zoneRepository->getDomainNameById($zoneId);
            if (!$zoneName) {
                $this->logError("Zone not found with ID: $zoneId");
                return false;
            }

            // Get recipient (removed owner) details
            $recipient = $this->getUserDetails($removedOwnerId);
            if (!$recipient || empty($recipient['email'])) {
                $this->logWarning("User $removedOwnerId has no email address, skipping notification");
                return false;
            }

            // Get revoker details
            $revoker = $this->getUserDetails($revokedById);
            if (!$revoker) {
                $this->logError("User not found with ID: $revokedById");
                return false;
            }

            // Render email templates
            $templates = $this->emailTemplateService->renderZoneAccessRevokedEmail(
                $zoneName,
                $recipient['fullname'] ?: $recipient['username'],
                $revoker['fullname'] ?: $revoker['username'],
                date('Y-m-d H:i:s')
            );

            // Send email
            $result = $this->mailService->sendMail(
                $recipient['email'],
                $templates['subject'],
                $templates['html'],
                $templates['text']
            );

            if ($result) {
                $this->logInfo("Access revoked notification sent for zone '$zoneName' to user '{$recipient['username']}'");
            } else {
                $this->logError("Failed to send access revoked notification for zone '$zoneName' to user '{$recipient['username']}'");
            }

            return $result;
        } catch (LoaderError | RuntimeError | SyntaxError $e) {
            $this->logError("Template error sending access revoked notification: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->logError("Error sending access revoked notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if zone access notifications are enabled
     *
     * @return bool True if notifications are enabled and mail is configured
     */
    private function isNotificationEnabled(): bool
    {
        // Check if notifications are enabled
        if (!$this->config->get('notifications', 'zone_access_enabled', false)) {
            return false;
        }

        // Check if mail is enabled
        if (!$this->config->get('mail', 'enabled', false)) {
            $this->logWarning("Zone access notifications enabled but mail is disabled");
            return false;
        }

        return true;
    }

    /**
     * Get user details from database
     *
     * @param int $userId User ID
     * @return array|null User details array with keys: id, username, fullname, email
     */
    private function getUserDetails(int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, username, fullname, email
            FROM users
            WHERE id = :user_id
            LIMIT 1
        ');

        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /**
     * Log info message
     *
     * @param string $message Message to log
     */
    private function logInfo(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->info($message);
        }
    }

    /**
     * Log warning message
     *
     * @param string $message Message to log
     */
    private function logWarning(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        }
    }

    /**
     * Log error message
     *
     * @param string $message Message to log
     */
    private function logError(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message);
        }
    }
}
