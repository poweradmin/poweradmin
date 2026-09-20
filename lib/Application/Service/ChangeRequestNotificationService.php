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

use PDO;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\ChangeRequestNotifierInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Writes the zone audit line for each change request event, then emails the
 * reviewers of the zone when a request is filed and the requester when it is
 * decided. Mail failures are logged and reported as false; nothing here throws
 * to the caller, so a mail problem never blocks a request.
 */
class ChangeRequestNotificationService implements ChangeRequestNotifierInterface
{
    public const KIND_RECORDS = 'records';
    public const KIND_ZONE_DELETE = 'zone_delete';

    private PDO $db;
    private ConfigurationInterface $config;
    private MailService $mailService;
    private EmailTemplateService $emailTemplateService;
    private DomainRepositoryInterface $domainRepository;
    private PermissionService $permissions;
    private UrlService $urlService;
    private LoggerInterface $logger;

    /**
     * @param LoggerInterface|null $logger Omit to discard log lines
     */
    public function __construct(
        PDO $db,
        ConfigurationInterface $config,
        MailService $mailService,
        EmailTemplateService $emailTemplateService,
        DomainRepositoryInterface $domainRepository,
        PermissionService $permissions,
        ?LoggerInterface $logger = null,
        private readonly ?AuditService $audit = null,
        private readonly ?SOARecordManagerInterface $soaRecords = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->mailService = $mailService;
        $this->emailTemplateService = $emailTemplateService;
        $this->domainRepository = $domainRepository;
        $this->permissions = $permissions;
        $this->urlService = new UrlService($config);
        $this->logger = $logger ?? new NullLogger();
    }

    public function requestFiled(ZoneChangeRequest $request): void
    {
        $this->audit?->logChangeRequest($request->zoneId, $request->zoneName, $request->id, 'filed', $request->requestComment);
        $this->notifyRequestFiled($request->id, $request->zoneId, $request->zoneName, (int)$request->requesterId, $request->requesterName, $request->requestComment, $request->kind);
    }

    public function requestDecided(ZoneChangeRequest $request): void
    {
        $this->audit?->logChangeRequest($request->zoneId, $request->zoneName, $request->id, $request->status, $request->reviewComment);
        if ($request->requesterId === null || $request->reviewerId === null) {
            return;
        }
        $this->notifyRequestDecided($request->id, $request->zoneId, $request->zoneName, $request->requesterId, $request->status, $request->reviewerId, $request->reviewComment);
    }

    public function requestCancelled(ZoneChangeRequest $request): void
    {
        $this->audit?->logChangeRequest($request->zoneId, $request->zoneName, $request->id, 'cancelled');
    }

    /**
     * Mail every reviewer of the zone about a newly filed request.
     *
     * Reviewers are the active users with an email address for whom
     * ChangeApprovalPolicy::canReview() holds, minus the requester. This walks
     * all active users, which is acceptable because filing a request is rare.
     *
     * @param string $kind KIND_RECORDS or KIND_ZONE_DELETE
     * @return bool True when at least one reviewer was mailed and no send failed
     */
    public function notifyRequestFiled(
        int $requestId,
        int $zoneId,
        string $zoneName,
        int $requesterId,
        string $requesterName,
        ?string $comment,
        string $kind
    ): bool {
        if (!$this->isNotificationEnabled()) {
            return false;
        }

        try {
            $zoneName = $this->resolveZoneName($zoneId, $zoneName);
            $reviewers = $this->findReviewers($zoneId, $requesterId);
            $soaContact = $this->soaContact($zoneId, $reviewers);
            if ($soaContact !== null) {
                $reviewers[] = $soaContact;
            }
            if ($reviewers === []) {
                $this->logger->info("No reviewer with an email address for zone '$zoneName', change request $requestId not announced");
                return false;
            }

            $requestUrl = $this->urlService->getChangeRequestUrl($requestId);
            $sent = 0;
            // Replies go to the person who asked for the change
            $headers = $this->replyToRequester($requesterId);

            foreach ($reviewers as $reviewer) {
                $templates = $this->emailTemplateService->renderChangeRequestFiledEmail(
                    $zoneName,
                    $requestId,
                    $reviewer['fullname'] ?: $reviewer['username'],
                    $requesterName,
                    $kind,
                    $comment ?? '',
                    date('Y-m-d H:i:s'),
                    $requestUrl
                );

                $result = $this->mailService->sendMail(
                    $reviewer['email'],
                    $templates['subject'],
                    $templates['html'],
                    $templates['text'],
                    $headers
                );

                if ($result) {
                    $sent++;
                } else {
                    $this->logger->error("Failed to send change request $requestId notification for zone '$zoneName' to user '{$reviewer['username']}'");
                }
            }

            $this->logger->info("Change request $requestId for zone '$zoneName' announced to $sent of " . count($reviewers) . " reviewers");

            return $sent === count($reviewers);
        } catch (LoaderError | RuntimeError | SyntaxError $e) {
            $this->logger->error("Template error sending change request filed notification: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Error sending change request filed notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mail the requester about the outcome of their request.
     *
     * @param string $status 'approved', 'rejected' or 'failed'
     * @return bool True if the notification was sent
     */
    public function notifyRequestDecided(
        int $requestId,
        int $zoneId,
        string $zoneName,
        int $requesterId,
        string $status,
        int $reviewerId,
        ?string $reviewComment
    ): bool {
        if (!$this->isNotificationEnabled()) {
            return false;
        }

        try {
            $zoneName = $this->resolveZoneName($zoneId, $zoneName);

            $requester = $this->getUserDetails($requesterId);
            if (!$requester || empty($requester['email'])) {
                $this->logger->warning("User $requesterId has no email address, skipping change request $requestId decision notification");
                return false;
            }

            $reviewer = $this->getUserDetails($reviewerId);
            $reviewerName = $reviewer ? ($reviewer['fullname'] ?: $reviewer['username']) : '';

            $templates = $this->emailTemplateService->renderChangeRequestDecidedEmail(
                $zoneName,
                $requestId,
                $requester['fullname'] ?: $requester['username'],
                $status,
                $reviewerName,
                $reviewComment ?? '',
                date('Y-m-d H:i:s'),
                $this->urlService->getChangeRequestUrl($requestId)
            );

            $result = $this->mailService->sendMail(
                $requester['email'],
                $templates['subject'],
                $templates['html'],
                $templates['text']
            );

            if ($result) {
                $this->logger->info("Change request $requestId decision ($status) for zone '$zoneName' sent to user '{$requester['username']}'");
            } else {
                $this->logger->error("Failed to send change request $requestId decision for zone '$zoneName' to user '{$requester['username']}'");
            }

            return $result;
        } catch (LoaderError | RuntimeError | SyntaxError $e) {
            $this->logger->error("Template error sending change request decision notification: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Error sending change request decision notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if change request notifications are enabled
     *
     * @return bool True if notifications are enabled and mail is configured
     */
    private function isNotificationEnabled(): bool
    {
        if (!$this->config->get('notifications', 'change_request_enabled', false)) {
            return false;
        }

        if (!$this->config->get('mail', 'enabled', false)) {
            $this->logger->warning("Change request notifications enabled but mail is disabled");
            return false;
        }

        return true;
    }

    /**
     * Callers pass the zone name they already hold; the repository is only asked
     * when it is empty (for example after the zone was deleted).
     */
    private function resolveZoneName(int $zoneId, string $zoneName): string
    {
        if ($zoneName !== '') {
            return $zoneName;
        }

        return $this->domainRepository->getDomainNameById($zoneId) ?: "zone #$zoneId";
    }

    /**
     * Active users with an email address who may review requests for the zone,
     * excluding the requester.
     *
     * @return array<int, array{id: int, username: string, fullname: string, email: string}>
     */
    /**
     * @return array<string, string> Reply-To header for the requester, or nothing when they have no address
     */
    private function replyToRequester(int $requesterId): array
    {
        $requester = $this->getUserDetails($requesterId);
        if ($requester === null || empty($requester['email'])) {
            return [];
        }

        return ['Reply-To' => $requester['email']];
    }

    /**
     * The zone's SOA contact as a recipient when notifications.change_request_soa_contact
     * is on: the RNAME with its first label turned into the mailbox, as dns-ui does.
     * Skipped when it already is a reviewer's address.
     *
     * @param list<array{id: int, username: string, fullname: string, email: string}> $reviewers
     * @return array{id: int, username: string, fullname: string, email: string}|null
     */
    private function soaContact(int $zoneId, array $reviewers): ?array
    {
        if ($this->soaRecords === null || !$this->config->get('notifications', 'change_request_soa_contact', false)) {
            return null;
        }
        $fields = preg_split('/\s+/', trim($this->soaRecords->getSOARecord($zoneId)));
        $rname = rtrim((string)($fields[1] ?? ''), '.');
        // The mailbox ends at the first unescaped dot; "\." inside it is a literal dot (RFC 1035)
        if (!preg_match('/^((?:\\\\.|[^.\\\\])+)\\.(.+)$/', $rname, $parts)) {
            return null;
        }
        $email = stripslashes($parts[1]) . '@' . $parts[2];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        foreach ($reviewers as $reviewer) {
            if (strcasecmp($reviewer['email'], $email) === 0) {
                return null;
            }
        }

        return ['id' => 0, 'username' => $email, 'fullname' => '', 'email' => $email];
    }

    private function findReviewers(int $zoneId, int $requesterId): array
    {
        $stmt = $this->db->prepare('
            SELECT id, username, fullname, email
            FROM users
            WHERE active = 1 AND email IS NOT NULL AND email <> :empty
            ORDER BY id
        ');
        $stmt->execute(['empty' => '']);

        $reviewers = [];
        while ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $userId = (int)$user['id'];
            if ($userId === $requesterId) {
                continue;
            }

            $canReview = ChangeApprovalPolicy::canReview(
                $this->permissions->getChangeApprovePermissionLevelForZone($userId, $zoneId),
                $this->permissions->getEditPermissionLevelForZone($userId, $zoneId),
                $this->permissions->userOwnsZone($userId, $zoneId)
            );

            if ($canReview) {
                $reviewers[] = [
                    'id' => $userId,
                    'username' => (string)$user['username'],
                    'fullname' => (string)($user['fullname'] ?? ''),
                    'email' => (string)$user['email'],
                ];
            }
        }

        return $reviewers;
    }

    /**
     * Get user details from database
     *
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
}
