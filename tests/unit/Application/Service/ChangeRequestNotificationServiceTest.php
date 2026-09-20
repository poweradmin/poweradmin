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

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ChangeRequestNotificationService;
use Poweradmin\Application\Service\EmailTemplateService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use TestHelpers\BuildsPermissionService;
use TestHelpers\FakeConfiguration;

#[CoversClass(ChangeRequestNotificationService::class)]
class ChangeRequestNotificationServiceTest extends TestCase
{
    use BuildsPermissionService;

    private const ZONE_ID = 42;
    private const ZONE_NAME = 'example.com';
    private const REQUESTER_ID = 1;
    private const OWNER_APPROVER_ID = 2;
    private const GLOBAL_APPROVER_ID = 3;
    private const APPROVER_WITHOUT_EMAIL_ID = 4;
    private const EDITOR_WITHOUT_APPROVE_ID = 5;
    private const INACTIVE_APPROVER_ID = 6;
    private const OTHER_ZONE_OWNER_APPROVER_ID = 7;

    /** @var array<int, array{to: string, subject: string, html: string, text: string}> */
    private array $sent = [];

    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            fullname TEXT,
            email TEXT,
            active INTEGER NOT NULL DEFAULT 1
        )');

        $rows = [
            [self::REQUESTER_ID, 'requester', 'Rita Requester', 'rita@example.com', 1],
            [self::OWNER_APPROVER_ID, 'owner', 'Olaf Owner', 'olaf@example.com', 1],
            [self::GLOBAL_APPROVER_ID, 'global', '', 'global@example.com', 1],
            [self::APPROVER_WITHOUT_EMAIL_ID, 'noemail', 'No Email', '', 1],
            [self::EDITOR_WITHOUT_APPROVE_ID, 'editor', 'Ed Editor', 'ed@example.com', 1],
            [self::INACTIVE_APPROVER_ID, 'inactive', 'Ina Inactive', 'ina@example.com', 0],
            [self::OTHER_ZONE_OWNER_APPROVER_ID, 'otherowner', 'Otto Other', 'otto@example.com', 1],
        ];
        $stmt = $this->db->prepare('INSERT INTO users (id, username, fullname, email, active) VALUES (?, ?, ?, ?, ?)');
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    private function defaultPermissions(): PermissionService
    {
        $approveOwn = [Permission::PERM_ZONE_CHANGE_APPROVE_OWN, Permission::PERM_ZONE_CONTENT_EDIT_OWN];

        return $this->buildPermissionService(
            [
                self::REQUESTER_ID => [Permission::PERM_ZONE_CHANGE_REQUEST_OWN, Permission::PERM_ZONE_CHANGE_APPROVE_OWN, Permission::PERM_ZONE_CONTENT_EDIT_OWN],
                self::OWNER_APPROVER_ID => $approveOwn,
                self::GLOBAL_APPROVER_ID => [Permission::PERM_ZONE_CHANGE_APPROVE_OTHERS, Permission::PERM_ZONE_CONTENT_EDIT_OTHERS],
                self::APPROVER_WITHOUT_EMAIL_ID => $approveOwn,
                self::EDITOR_WITHOUT_APPROVE_ID => [Permission::PERM_ZONE_CONTENT_EDIT_OTHERS],
                self::INACTIVE_APPROVER_ID => $approveOwn,
                self::OTHER_ZONE_OWNER_APPROVER_ID => $approveOwn,
            ],
            [],
            [
                self::REQUESTER_ID => [self::ZONE_ID],
                self::OWNER_APPROVER_ID => [self::ZONE_ID],
                self::APPROVER_WITHOUT_EMAIL_ID => [self::ZONE_ID],
                self::INACTIVE_APPROVER_ID => [self::ZONE_ID],
                self::OTHER_ZONE_OWNER_APPROVER_ID => [99],
            ]
        );
    }

    private function makeService(
        bool $notificationsEnabled = true,
        bool $mailEnabled = true,
        bool $sendResult = true,
        ?PermissionService $permissions = null,
        ?AuditService $audit = null
    ): ChangeRequestNotificationService {
        $config = new FakeConfiguration([
            'notifications' => ['change_request_enabled' => $notificationsEnabled],
            'mail' => ['enabled' => $mailEnabled],
            'interface' => ['application_url' => 'https://dns.example.test/pa'],
        ]);

        $mailService = $this->createMock(MailService::class);
        $mailService->method('sendMail')->willReturnCallback(
            function (string $to, string $subject, string $body, string $plainBody = '') use ($sendResult): bool {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $body, 'text' => $plainBody];
                return $sendResult;
            }
        );

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainNameById')->willReturn(self::ZONE_NAME);

        return new ChangeRequestNotificationService(
            $this->db,
            $config,
            $mailService,
            new EmailTemplateService($config),
            $domainRepository,
            $permissions ?? $this->defaultPermissions(),
            null,
            $audit
        );
    }

    private function fileRequest(ChangeRequestNotificationService $service): bool
    {
        return $service->notifyRequestFiled(7, self::ZONE_ID, self::ZONE_NAME, self::REQUESTER_ID, 'Rita Requester', 'Please add the MX record', 'records');
    }

    public function testDisabledNotificationsSendNothing(): void
    {
        $service = $this->makeService(notificationsEnabled: false);

        $this->assertFalse($this->fileRequest($service));
        $this->assertFalse($service->notifyRequestDecided(7, self::ZONE_ID, self::ZONE_NAME, self::REQUESTER_ID, 'approved', self::OWNER_APPROVER_ID, null));
        $this->assertSame([], $this->sent);
    }

    public function testDisabledMailSendsNothingEvenWhenNotificationsAreOn(): void
    {
        $service = $this->makeService(mailEnabled: false);

        $this->assertFalse($this->fileRequest($service));
        $this->assertSame([], $this->sent);
    }

    public function testFiledRequestMailsEveryReviewerExceptTheRequester(): void
    {
        $this->assertTrue($this->fileRequest($this->makeService()));

        $recipients = array_column($this->sent, 'to');
        sort($recipients);
        $this->assertSame(['global@example.com', 'olaf@example.com'], $recipients);

        $first = $this->sent[0];
        $this->assertSame('Change Request #7 Filed: example.com', $first['subject']);
        $this->assertStringContainsString('example.com', $first['html']);
        $this->assertStringContainsString('https://dns.example.test/pa/zones/requests/7', $first['html']);
        $this->assertStringContainsString('https://dns.example.test/pa/zones/requests/7', $first['text']);
        $this->assertStringContainsString('Rita Requester', $first['text']);
        $this->assertStringContainsString('Please add the MX record', $first['text']);
        $this->assertStringContainsString('Record changes', $first['text']);
    }

    public function testFiledRequestReturnsFalseWhenNoReviewerHasAnEmail(): void
    {
        $permissions = $this->buildPermissionService(
            [self::APPROVER_WITHOUT_EMAIL_ID => [Permission::PERM_ZONE_CHANGE_APPROVE_OTHERS, Permission::PERM_ZONE_CONTENT_EDIT_OTHERS]]
        );

        $this->assertFalse($this->fileRequest($this->makeService(permissions: $permissions)));
        $this->assertSame([], $this->sent);
    }

    public function testEveryEventWritesAnAuditLineEvenWithNotificationsOff(): void
    {
        $audit = $this->createMock(AuditService::class);
        $events = [];
        $audit->method('logChangeRequest')->willReturnCallback(function (int $zoneId, string $zoneName, int $requestId, string $event, ?string $comment) use (&$events): void {
            $events[] = [$zoneId, $requestId, $event, $comment];
        });
        $service = $this->makeService(notificationsEnabled: false, audit: $audit);

        $service->requestFiled($this->request(ZoneChangeRequest::STATUS_PENDING, 'add it'));
        $service->requestDecided($this->request(ZoneChangeRequest::STATUS_REJECTED, 'add it', 'no'));
        $service->requestCancelled($this->request(ZoneChangeRequest::STATUS_CANCELLED, 'add it'));

        $this->assertSame([
            [self::ZONE_ID, 7, 'filed', 'add it'],
            [self::ZONE_ID, 7, 'rejected', 'no'],
            [self::ZONE_ID, 7, 'cancelled', null],
        ], $events);
        $this->assertSame([], $this->sent);
    }

    private function request(string $status, ?string $comment, ?string $reviewComment = null): ZoneChangeRequest
    {
        $decided = $status !== ZoneChangeRequest::STATUS_PENDING;

        return new ZoneChangeRequest(
            7,
            self::ZONE_ID,
            self::ZONE_NAME,
            ZoneChangeRequest::KIND_RECORDS,
            $status,
            self::REQUESTER_ID,
            'rita',
            $comment,
            null,
            [['op' => 'add', 'after' => ['name' => 'mx.example.com', 'type' => 'A', 'content' => '192.0.2.9', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'comment' => '']]],
            null,
            $decided ? self::OWNER_APPROVER_ID : null,
            $decided ? 'olaf' : null,
            $reviewComment,
            '2026-09-20 10:00:00',
            $decided ? '2026-09-20 11:00:00' : null,
            null,
            null
        );
    }

    public function testDecidedRequestMailsTheRequester(): void
    {
        $service = $this->makeService();

        $this->assertTrue($service->notifyRequestDecided(7, self::ZONE_ID, self::ZONE_NAME, self::REQUESTER_ID, 'rejected', self::OWNER_APPROVER_ID, 'Wrong TTL'));

        $this->assertCount(1, $this->sent);
        $mail = $this->sent[0];
        $this->assertSame('rita@example.com', $mail['to']);
        $this->assertSame('Change Request #7 Rejected: example.com', $mail['subject']);
        $this->assertStringContainsString('Olaf Owner', $mail['text']);
        $this->assertStringContainsString('Wrong TTL', $mail['text']);
        $this->assertStringContainsString('https://dns.example.test/pa/zones/requests/7', $mail['html']);
    }

    public function testDecidedRequestSkipsARequesterWithoutEmail(): void
    {
        $service = $this->makeService();

        $this->assertFalse($service->notifyRequestDecided(7, self::ZONE_ID, self::ZONE_NAME, self::APPROVER_WITHOUT_EMAIL_ID, 'approved', self::OWNER_APPROVER_ID, null));
        $this->assertSame([], $this->sent);
    }

    public function testMailFailureReturnsFalseWithoutThrowing(): void
    {
        $service = $this->makeService(sendResult: false);

        $this->assertFalse($this->fileRequest($service));
        $this->assertCount(2, $this->sent);
        $this->assertFalse($service->notifyRequestDecided(7, self::ZONE_ID, self::ZONE_NAME, self::REQUESTER_ID, 'approved', self::OWNER_APPROVER_ID, null));
    }

    public function testMailExceptionIsSwallowedAndReportedAsFalse(): void
    {
        $config = new FakeConfiguration([
            'notifications' => ['change_request_enabled' => true],
            'mail' => ['enabled' => true],
        ]);
        $mailService = $this->createMock(MailService::class);
        $mailService->method('sendMail')->willThrowException(new \RuntimeException('smtp down'));

        $service = new ChangeRequestNotificationService(
            $this->db,
            $config,
            $mailService,
            new EmailTemplateService($config),
            $this->createMock(DomainRepositoryInterface::class),
            $this->defaultPermissions()
        );

        $this->assertFalse($this->fileRequest($service));
    }
}
