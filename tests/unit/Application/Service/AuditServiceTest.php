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
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Enum\LoginFailureReason;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Logger\AuditLogWriter;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

/**
 * The audit line shape is what the log views and grep filters read, so each
 * event keeps its "client_ip:.. user:.. operation:.." fields in a fixed order.
 */
class AuditServiceTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: int|null}> method, message, zone or group id */
    private array $lines = [];

    private function makeService(?string $loginUsername = 'alice'): AuditService
    {
        $logger = $this->createMock(AuditLogWriter::class);
        foreach (['logInfo', 'logWarn', 'logNotice', 'logError', 'logGroupInfo', 'logGroupWarning', 'logApiInfo'] as $method) {
            $logger->method($method)->willReturnCallback(function (string $message, ?int $id = null) use ($method): void {
                $this->lines[] = [$method, $message, $id];
            });
        }

        $ip = $this->createMock(IpAddressRetriever::class);
        $ip->method('getClientIp')->willReturn('192.0.2.10');
        $user = $this->createMock(UserContextService::class);
        $user->method('getActingUsername')->willReturn('alice');
        $user->method('getLoggedInUsername')->willReturn($loginUsername);

        return new AuditService($this->createMock(PDO::class), $logger, $ip, $user);
    }

    public function testZoneAddCarriesOnlyTheGivenFields(): void
    {
        $service = $this->makeService();
        $service->logZoneAdd(7, 'example.com', 'MASTER', 'none');
        $service->logZoneAdd(8, 'example.net', 'SLAVE', null, '192.0.2.1');

        $this->assertSame(
            ['logInfo', 'client_ip:192.0.2.10 user:alice operation:add_zone zone:example.com zone_type:MASTER zone_template:none', 7],
            $this->lines[0]
        );
        $this->assertSame(
            ['logInfo', 'client_ip:192.0.2.10 user:alice operation:add_zone zone:example.net zone_type:SLAVE zone_master:192.0.2.1', 8],
            $this->lines[1]
        );
    }

    public function testRecordDeleteLeavesPriorityOutWhenUnknown(): void
    {
        $service = $this->makeService();
        $service->logRecordDelete(3, 'MX', 'example.com', 'mail.example.com', 3600, 10);
        $service->logRecordDelete(3, 'A', 'www.example.com', '192.0.2.5', '3600', null);

        $this->assertSame(
            'client_ip:192.0.2.10 user:alice operation:delete_record record_type:MX record:example.com content:mail.example.com ttl:3600 priority:10',
            $this->lines[0][1]
        );
        $this->assertSame(
            'client_ip:192.0.2.10 user:alice operation:delete_record record_type:A record:www.example.com content:192.0.2.5 ttl:3600',
            $this->lines[1][1]
        );
    }

    public function testRecordEditListsOldAndNewValues(): void
    {
        $this->makeService()->logRecordEdit(
            4,
            ['type' => 'A', 'name' => 'a.example.com', 'content' => '192.0.2.1', 'ttl' => 300, 'prio' => 0],
            ['type' => 'A', 'name' => 'a.example.com', 'content' => '192.0.2.2', 'ttl' => 600]
        );

        $this->assertSame(
            'client_ip:192.0.2.10 user:alice operation:edit_record'
            . ' old_record_type:A old_record:a.example.com old_content:192.0.2.1 old_ttl:300 old_priority:0'
            . ' record_type:A record:a.example.com content:192.0.2.2 ttl:600 priority:',
            $this->lines[0][1]
        );
        $this->assertSame(4, $this->lines[0][2]);
    }

    public function testTemplateNamesAreLoggedAsSingleTokens(): void
    {
        $service = $this->makeService();
        $service->logZoneTemplateAdd('Web hosting');
        $service->logApiZoneTemplateRecordAdd(2, 9, 'mail server', 'MX');

        $this->assertSame('client_ip:192.0.2.10 user:alice operation:add_zone_template template_name:Web_hosting', $this->lines[0][1]);
        $this->assertNull($this->lines[0][2]);
        $this->assertSame(
            'client_ip:192.0.2.10 user:alice operation:api_add_zone_template_record template_id:2 record_id:9 record_name:mail_server record_type:MX',
            $this->lines[1][1]
        );
    }

    public function testGroupEventsGoToTheGroupLog(): void
    {
        $service = $this->makeService();
        $service->logGroupMembersAdd(3, 'DNS admins', ['bob', 'carol']);
        $service->logGroupZonesRemove(3, 'DNS admins', ['example.com']);
        $service->logGroupDelete(3, 'DNS admins', 2, 1);

        $this->assertSame(
            ['logGroupInfo', 'client_ip:192.0.2.10 user:alice operation:add_members group:DNS_admins group_id:3 count:2 members:bob,carol', 3],
            $this->lines[0]
        );
        $this->assertSame(
            ['logGroupInfo', 'client_ip:192.0.2.10 user:alice operation:remove_zones group:DNS_admins group_id:3 count:1 zones:example.com', 3],
            $this->lines[1]
        );
        $this->assertSame(
            ['logGroupWarning', 'client_ip:192.0.2.10 user:alice operation:delete_group group:DNS_admins group_id:3 members_affected:2 zones_affected:1', null],
            $this->lines[2]
        );
    }

    public function testAnonymousAccountFlowsCarryNoActor(): void
    {
        $service = $this->makeService();
        $service->logPasswordResetRequest('bob@example.com');
        $service->logPasswordReset(12);

        $this->assertSame('client_ip:192.0.2.10 operation:password_reset_request email:bob@example.com', $this->lines[0][1]);
        $this->assertSame('client_ip:192.0.2.10 operation:password_reset user_id:12', $this->lines[1][1]);
    }

    public function testApiKeyEventsGoToTheApiLog(): void
    {
        $service = $this->makeService();
        $service->logApiKeyToggle(4, 'ci', true);
        $service->logSsoLoginError(AuthMethod::OIDC, 'access_denied');

        $this->assertSame(['logApiInfo', 'client_ip:192.0.2.10 user:alice operation:api_key_toggle key_id:4 key_name:ci status:disabled', null], $this->lines[0]);
        $this->assertSame(['logWarn', 'client_ip:192.0.2.10 operation:login_error auth_method:oidc error:access_denied', null], $this->lines[1]);
    }

    public function testApiRequestLineKeepsTheOperationFirstAndDashesForEmptyValues(): void
    {
        $service = $this->makeService();
        $service->logApiRequest('api_request', 'HEAD', '/api/v2/zones', 200, '4', 'alice');
        $service->logApiRequest('api_violation', 'DELETE', '/api/v2/zones/7', 403, '-', '');

        $this->assertSame(
            ['logApiInfo', 'operation:api_request method:HEAD path:/api/v2/zones status:200 key_id:4 user:alice client_ip:192.0.2.10', null],
            $this->lines[0]
        );
        $this->assertSame(
            ['logApiInfo', 'operation:api_violation method:DELETE path:/api/v2/zones/7 status:403 key_id:- user:- client_ip:192.0.2.10', null],
            $this->lines[1]
        );
    }

    public function testDynamicDnsUpdateIsWrittenToTheUserLogAndTheZoneLog(): void
    {
        $this->makeService()->logDynamicDnsUpdate('ddns-client', 'host.example.com', 12, '192.0.2.77');

        $this->assertSame([
            ['logNotice', 'client_ip:192.0.2.10 user:ddns-client operation:dynamic_dns_update hostname:host.example.com zone_id:12 ip:192.0.2.77', null],
            ['logInfo', 'client_ip:192.0.2.10 user:ddns-client operation:dynamic_dns_update hostname:host.example.com zone_id:12 ip:192.0.2.77', 12],
        ], $this->lines);
    }

    public function testSamlLogoutUsesTheActorCapturedBeforeTheSessionWasCleared(): void
    {
        $this->makeService()->logSamlLogout('bob');

        $this->assertSame('client_ip:192.0.2.10 user:bob operation:saml_logout', $this->lines[0][1]);
    }

    public function testActorFallsBackToUnknownWithoutASession(): void
    {
        $logger = $this->createMock(AuditLogWriter::class);
        $logger->expects($this->once())->method('logWarn')
            ->with('client_ip:192.0.2.10 user:unknown operation:access_denied permission:zone_master_add uri:/zones/add/master', null);
        $ip = $this->createMock(IpAddressRetriever::class);
        $ip->method('getClientIp')->willReturn('192.0.2.10');
        $user = $this->createMock(UserContextService::class);
        $user->method('getActingUsername')->willReturn(null);

        (new AuditService($this->createMock(PDO::class), $logger, $ip, $user))
            ->logAccessDenied('zone_master_add', '/zones/add/master');
    }

    /**
     * The login line shape is load-bearing for the fail2ban filter in
     * poweradmin-docs, so any deviation needs to be deliberate.
     */
    public function testLoginOutcomesKeepTheFail2banLineShape(): void
    {
        $service = $this->makeService();
        $service->logLoginSuccess(AuthMethod::SQL);
        $service->logLoginFailed(AuthMethod::SQL);
        $service->logLoginFailed(AuthMethod::SQL, LoginFailureReason::WRONG_PASSWORD);
        $service->logLoginLocked(AuthMethod::SQL);

        $this->assertSame([
            ['logNotice', 'client_ip:192.0.2.10 user:alice operation:login_success auth_method:sql', null],
            ['logWarn', 'client_ip:192.0.2.10 user:alice operation:login_failed auth_method:sql', null],
            ['logWarn', 'client_ip:192.0.2.10 user:alice operation:login_failed auth_method:sql reason:wrong_password', null],
            ['logWarn', 'client_ip:192.0.2.10 user:alice operation:login_locked auth_method:sql', null],
        ], $this->lines);
    }

    public function testLoginFailedCarriesTheAuthMethod(): void
    {
        $service = $this->makeService();
        $service->logLoginFailed(AuthMethod::OIDC, LoginFailureReason::NO_SUCH_USER);
        $service->logLoginFailed(AuthMethod::SAML);
        $service->logLoginLocked(AuthMethod::LDAP);

        $this->assertSame('client_ip:192.0.2.10 user:alice operation:login_failed auth_method:oidc reason:no_such_user', $this->lines[0][1]);
        $this->assertSame('client_ip:192.0.2.10 user:alice operation:login_failed auth_method:saml', $this->lines[1][1]);
        $this->assertSame('client_ip:192.0.2.10 user:alice operation:login_locked auth_method:ldap', $this->lines[2][1]);
    }

    public function testLoginLineLeavesTheUserEmptyWhenNothingWasPosted(): void
    {
        $this->makeService(null)->logLoginFailed(AuthMethod::SQL, LoginFailureReason::NO_SUCH_USER);

        $this->assertSame(
            ['logWarn', 'client_ip:192.0.2.10 user: operation:login_failed auth_method:sql reason:no_such_user', null],
            $this->lines[0]
        );
    }

    public function testLdapFailuresMatchTheUnifiedShape(): void
    {
        $service = $this->makeService();
        $service->logLoginFailed(AuthMethod::LDAP, LoginFailureReason::WRONG_PASSWORD);
        $service->logLoginFailed(AuthMethod::LDAP, LoginFailureReason::NO_SUCH_USER);
        $service->logLoginFailed(AuthMethod::LDAP, LoginFailureReason::ACCOUNT_DISABLED);
        $service->logLoginFailed(AuthMethod::LDAP, LoginFailureReason::DUPLICATE_USERS);

        $this->assertSame([
            ['logWarn', 'client_ip:192.0.2.10 user:alice operation:login_failed auth_method:ldap reason:wrong_password', null],
            ['logWarn', 'client_ip:192.0.2.10 user:alice operation:login_failed auth_method:ldap reason:no_such_user', null],
            ['logWarn', 'client_ip:192.0.2.10 user:alice operation:login_failed auth_method:ldap reason:account_disabled', null],
            ['logError', 'client_ip:192.0.2.10 user:alice operation:login_failed auth_method:ldap reason:duplicate_users', null],
        ], $this->lines);
    }

    /**
     * Backend errors (LDAP server unreachable, bind or search failure) must NOT use
     * operation:login_failed, otherwise fail2ban would ban legitimate users during
     * an LDAP outage. They use operation:login_error at ERROR priority instead.
     */
    public function testLdapBackendErrorsUseADistinctOperation(): void
    {
        $service = $this->makeService();
        $service->logLoginError(AuthMethod::LDAP, LoginFailureReason::LDAP_CONNECT_FAILED);
        $service->logLoginError(AuthMethod::LDAP, LoginFailureReason::LDAP_BIND_FAILED);
        $service->logLoginError(AuthMethod::LDAP, LoginFailureReason::LDAP_SEARCH_FAILED);

        $this->assertSame([
            ['logError', 'client_ip:192.0.2.10 user:alice operation:login_error auth_method:ldap reason:ldap_connect', null],
            ['logError', 'client_ip:192.0.2.10 user:alice operation:login_error auth_method:ldap reason:ldap_bind', null],
            ['logError', 'client_ip:192.0.2.10 user:alice operation:login_error auth_method:ldap reason:ldap_search_failed', null],
        ], $this->lines);
        foreach ($this->lines as $line) {
            $this->assertStringNotContainsString('operation:login_failed', $line[1]);
        }
    }
}
